<?php

declare(strict_types=1);

namespace App\Domain;

interface SummaryCacheInterface
{
    public function get(string $bookId, string $chapterId): ?string;
    public function set(string $bookId, string $chapterId, string $summary): void;
}
