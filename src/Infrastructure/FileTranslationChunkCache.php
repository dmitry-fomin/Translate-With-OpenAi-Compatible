<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class FileTranslationChunkCache implements TranslationChunkCacheInterface
{
    /** @var array<string, array<string, array<string, array{translation: string, summary: string, glossary: array<string, string>}>>> */
    private array $cache = [];

    private string $cacheFile;

    public function __construct(string $cacheFile = '.chunk_cache.json')
    {
        $this->cacheFile = $cacheFile;
        if (file_exists($this->cacheFile)) {
            $content = (string) file_get_contents($this->cacheFile);
            if ($content !== '') {
                /** @var array<string, array<string, array<string, array{translation: string, summary: string, glossary: array<string, string>}>>> $decoded */
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR) ?: [];
                $this->cache = $decoded;
            }
        }
    }

    public function get(string $bookId, string $chapterId, int $chunkIndex, string $chunkHash): ?array
    {
        $key = $this->key($chunkIndex, $chunkHash);
        $entry = $this->cache[$bookId][$chapterId][$key] ?? null;
        if ($entry === null) {
            return null;
        }

        // Защита от частично битой записи.
        if (
            !isset($entry['translation'], $entry['summary'], $entry['glossary'])
            || !is_string($entry['translation'])
            || !is_string($entry['summary'])
            || !is_array($entry['glossary'])
        ) {
            return null;
        }

        return $entry;
    }

    public function set(
        string $bookId,
        string $chapterId,
        int $chunkIndex,
        string $chunkHash,
        string $translation,
        string $summary,
        array $glossary,
    ): void {
        $key = $this->key($chunkIndex, $chunkHash);
        $this->cache[$bookId] ??= [];
        $this->cache[$bookId][$chapterId] ??= [];
        $this->cache[$bookId][$chapterId][$key] = [
            'translation' => $translation,
            'summary' => $summary,
            'glossary' => $glossary,
        ];
        $this->save();
    }

    private function key(int $chunkIndex, string $chunkHash): string
    {
        return $chunkIndex . ':' . $chunkHash;
    }

    private function save(): void
    {
        file_put_contents(
            $this->cacheFile,
            json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }
}
