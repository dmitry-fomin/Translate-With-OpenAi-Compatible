<?php

declare(strict_types=1);

namespace App\Domain;

interface EpubRepositoryInterface
{
    public function load(string $path): Book;
    public function save(Book $book, string $outputPath): void;

    /**
     * Упаковать распакованный EPUB (папку) обратно в .epub-файл.
     * mimetype должен идти первым и без компрессии.
     */
    public function pack(string $sourceFolder, string $outputFile): void;
}
