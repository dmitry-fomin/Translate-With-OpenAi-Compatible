<?php

declare(strict_types=1);

namespace App\Domain;

interface TranslationCacheInterface
{
    public function get(string $bookId, string $relativeFilePath): ?string;

    public function set(string $bookId, string $relativeFilePath, string $translated): void;
}
