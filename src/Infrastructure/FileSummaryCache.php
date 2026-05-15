<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\SummaryCacheInterface;

class FileSummaryCache implements SummaryCacheInterface
{
    private array $cache = [];
    private string $cacheFile;

    public function __construct(string $cacheFile = '.summary_cache.json')
    {
        $this->cacheFile = $cacheFile;
        if (file_exists($this->cacheFile)) {
            $content = file_get_contents($this->cacheFile);
            $this->cache = json_decode($content, true, 512, JSON_THROW_ON_ERROR) ?: [];
        }
    }

    public function get(string $bookId, string $chapterId): ?string
    {
        return $this->cache[$bookId][$chapterId] ?? null;
    }

    public function set(string $bookId, string $chapterId, string $summary): void
    {
        $this->cache[$bookId] ??= [];
        $this->cache[$bookId][$chapterId] = $summary;
        $this->save();
    }

    private function save(): void
    {
        file_put_contents($this->cacheFile, json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
