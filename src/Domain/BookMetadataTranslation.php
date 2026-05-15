<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class BookMetadataTranslation
{
    public function __construct(
        public string $title,
        public ?string $author = null,
    ) {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('BookMetadataTranslation: title must not be empty');
        }
    }
}
