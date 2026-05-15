<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\TranslationCacheInterface;

final class FileTranslationCache implements TranslationCacheInterface
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    private string $cacheFile;

    public function __construct(string $cacheFile = '.translation_cache.json')
    {
        $this->cacheFile = $cacheFile;
        if (file_exists($this->cacheFile)) {
            $content = (string) file_get_contents($this->cacheFile);
            if ($content !== '') {
                /** @var array<string, array<string, string>> $decoded */
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR) ?: [];
                $this->cache = $decoded;
            }
        }
    }

    public function get(string $bookId, string $relativeFilePath): ?string
    {
        return $this->cache[$bookId][$relativeFilePath] ?? null;
    }

    public function set(string $bookId, string $relativeFilePath, string $translated): void
    {
        $this->cache[$bookId] ??= [];
        $this->cache[$bookId][$relativeFilePath] = $translated;
        $this->save();
    }

    private function save(): void
    {
        file_put_contents(
            $this->cacheFile,
            json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }
}
