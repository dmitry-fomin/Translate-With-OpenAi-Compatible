<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\TranslationScope;
use App\Domain\TranslatorInterface;
use App\Infrastructure\LLM\LlmClientInterface;

readonly class LlmTranslator implements TranslatorInterface
{
    /**
     * Порог, при превышении которого глава режется на чанки и переводится
     * по частям с накоплением summary+glossary между чанками. ~8000 байт
     * грубо соответствует ~2000 токенам английского xhtml.
     */
    private const CHUNK_THRESHOLD_BYTES = 8000;

    public function __construct(
        private LlmClientInterface $llmClient,
        private string $targetLanguage = 'Russian',
        private string $translatePrompt = 'You are a professional book translator. Translate the following text to {language}. Maintain the style and tone of the original.',
        private TextSplitter $splitter = new TextSplitter(),
        private int $chunkSizeBytes = self::CHUNK_THRESHOLD_BYTES,
        private ?TranslationChunkCacheInterface $chunkCache = null,
    ) {
    }

    public function translate(string $text, string $context = '', ?TranslationScope $scope = null): string
    {
        $baseSystemPrompt = str_replace('{language}', $this->targetLanguage, $this->translatePrompt);

        if (strlen($text) <= $this->chunkSizeBytes) {
            return $this->translateWhole($baseSystemPrompt, $text, $context);
        }

        return $this->translateChunked($baseSystemPrompt, $text, $context, $scope);
    }

    private function translateWhole(string $baseSystemPrompt, string $text, string $outerContext): string
    {
        $systemPrompt = $baseSystemPrompt;
        if ($outerContext !== '') {
            $systemPrompt .= "\nContext of previous chapters:\n" . $outerContext;
        }

        return $this->llmClient->request($systemPrompt, $text);
    }

    private function translateChunked(string $baseSystemPrompt, string $text, string $outerContext, ?TranslationScope $scope): string
    {
        $chunks = $this->splitter->splitXhtml($text, $this->chunkSizeBytes);

        $runningSummary = $outerContext;
        /** @var array<string,string> $glossary */
        $glossary = [];
        $translatedParts = [];

        $useCache = $this->chunkCache !== null && $scope !== null;

        foreach ($chunks as $i => $chunk) {
            $chunkHash = sha1($chunk);

            if ($useCache) {
                /** @var TranslationChunkCacheInterface $cache */
                $cache = $this->chunkCache;
                /** @var TranslationScope $sc */
                $sc = $scope;
                $cached = $cache->get($sc->bookId, $sc->chapterId, $i, $chunkHash);
                if ($cached !== null) {
                    $translatedParts[] = $cached['translation'];
                    $runningSummary = $cached['summary'];
                    $glossary = $cached['glossary'];
                    continue;
                }
            }

            $systemPrompt = $this->buildChunkSystemPrompt(
                base: $baseSystemPrompt,
                chunkIndex: $i + 1,
                chunkCount: count($chunks),
                runningSummary: $runningSummary,
                glossary: $glossary,
            );

            $raw = $this->llmClient->request($systemPrompt, $chunk);
            $parsed = $this->parseChunkResponse($raw, $i + 1);

            $translatedParts[] = $parsed['translation'];
            $runningSummary = $parsed['summary'];
            $glossary = $parsed['glossary'];

            if ($useCache) {
                /** @var TranslationChunkCacheInterface $cache */
                $cache = $this->chunkCache;
                /** @var TranslationScope $sc */
                $sc = $scope;
                $cache->set(
                    $sc->bookId,
                    $sc->chapterId,
                    $i,
                    $chunkHash,
                    $parsed['translation'],
                    $parsed['summary'],
                    $parsed['glossary'],
                );
            }
        }

        return implode('', $translatedParts);
    }

    /**
     * @param array<string,string> $glossary
     */
    private function buildChunkSystemPrompt(
        string $base,
        int $chunkIndex,
        int $chunkCount,
        string $runningSummary,
        array $glossary,
    ): string {
        $glossaryJson = $glossary === []
            ? '{}'
            : json_encode($glossary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $runningSummaryBlock = $runningSummary !== '' ? $runningSummary : '(empty)';

        return $base . <<<PROMPT


            ---
            CHUNKED TRANSLATION MODE
            You are translating chunk {$chunkIndex} of {$chunkCount} from one chapter. The chapter has been split along xhtml block boundaries; treat each chunk as a continuation of the previous one.

            You MUST return a strict JSON object with exactly these keys, and NOTHING else (no markdown fences, no commentary, no leading or trailing text):
            {
              "translation": "<translated xhtml of THIS chunk; preserve all xhtml tags exactly; do NOT add tags that were not in the source chunk>",
              "summary": "<short English summary that combines the previous summary with what happened in this chunk; max ~300 words; will be passed back to you as context for the next chunk>",
              "glossary": { "<source name or term>": "<canonical translation>", ... }
            }

            Glossary rules:
            - Carry over ALL entries from the provided glossary into your response.
            - Add new proper nouns, character names, place names, and recurring domain terms that appear in this chunk.
            - Use these translations CONSISTENTLY: if "Mr. Smith" is "мистер Смит" in the glossary, do not switch to "м-р Смит".

            Previous running summary:
            {$runningSummaryBlock}

            Glossary so far (JSON):
            {$glossaryJson}
            PROMPT;
    }

    /**
     * @return array{translation: string, summary: string, glossary: array<string,string>}
     */
    private function parseChunkResponse(string $raw, int $chunkIndex): array
    {
        $payload = $this->extractJson($raw);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                sprintf('Chunk #%d: LLM returned invalid JSON: %s', $chunkIndex, $e->getMessage()),
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Chunk #%d: expected JSON object, got %s', $chunkIndex, gettype($decoded)));
        }

        $translation = $decoded['translation'] ?? null;
        $summary = $decoded['summary'] ?? null;
        $glossaryRaw = $decoded['glossary'] ?? [];

        if (!is_string($translation) || $translation === '') {
            throw new \RuntimeException(sprintf('Chunk #%d: missing or empty "translation" in LLM JSON', $chunkIndex));
        }
        if (!is_string($summary)) {
            throw new \RuntimeException(sprintf('Chunk #%d: missing or non-string "summary" in LLM JSON', $chunkIndex));
        }
        if (!is_array($glossaryRaw)) {
            throw new \RuntimeException(sprintf('Chunk #%d: "glossary" must be an object', $chunkIndex));
        }

        $glossary = [];
        foreach ($glossaryRaw as $k => $v) {
            if (is_string($k) && is_string($v) && $k !== '' && $v !== '') {
                $glossary[$k] = $v;
            }
        }

        return [
            'translation' => $translation,
            'summary' => $summary,
            'glossary' => $glossary,
        ];
    }

    /**
     * Снимает обёртку из markdown-fence ```json ... ``` если модель её добавила.
     */
    private function extractJson(string $raw): string
    {
        $trimmed = trim($raw);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = (string) preg_replace('/^```(?:json)?\s*\n?/u', '', $trimmed);
            $trimmed = (string) preg_replace('/\n?```\s*$/u', '', $trimmed);
            $trimmed = trim($trimmed);
        }

        return $trimmed;
    }
}
