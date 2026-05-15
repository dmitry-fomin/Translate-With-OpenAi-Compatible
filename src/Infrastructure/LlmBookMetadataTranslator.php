<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\BookMetadataTranslation;
use App\Domain\BookMetadataTranslatorInterface;
use App\Infrastructure\LLM\LlmClientInterface;

final readonly class LlmBookMetadataTranslator implements BookMetadataTranslatorInterface
{
    public function __construct(
        private LlmClientInterface $client,
        private string $systemPrompt,
        private string $targetLanguage,
    ) {
    }

    public function translate(string $originalTitle, ?string $originalAuthor): BookMetadataTranslation
    {
        $userPayload = [
            'target_language' => $this->targetLanguage,
            'original_title' => $originalTitle,
            'original_author' => $originalAuthor,
        ];
        $userPrompt = json_encode($userPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $raw = $this->client->request($this->systemPrompt, $userPrompt);

        $payload = $this->extractJson($raw);

        $title = $payload['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            throw new \RuntimeException('Metadata LLM response: missing or empty "title" field. Raw: ' . $raw);
        }

        $author = $payload['author'] ?? null;
        if ($author !== null && (!is_string($author) || trim($author) === '')) {
            $author = null;
        }

        return new BookMetadataTranslation($title, $author);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJson(string $raw): array
    {
        $trimmed = trim($raw);

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $trimmed, $m) === 1) {
            $trimmed = $m[1];
        }

        if (!str_starts_with($trimmed, '{')) {
            $start = strpos($trimmed, '{');
            $end = strrpos($trimmed, '}');
            if ($start === false || $end === false || $end <= $start) {
                throw new \RuntimeException('Metadata LLM response: not a JSON object. Raw: ' . $raw);
            }
            $trimmed = substr($trimmed, $start, $end - $start + 1);
        }

        try {
            $decoded = json_decode($trimmed, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Metadata LLM response: invalid JSON: ' . $e->getMessage() . '. Raw: ' . $raw, 0, $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Metadata LLM response: JSON is not an object. Raw: ' . $raw);
        }

        return $decoded;
    }
}
