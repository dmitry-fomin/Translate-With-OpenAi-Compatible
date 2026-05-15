<?php

declare(strict_types=1);

namespace App\Application;

final readonly class CoverTranslationOverrides
{
    public function __construct(
        public ?string $translatedTitle = null,
        public ?string $originalTitle = null,
        public ?string $translatedAuthor = null,
        public ?string $originalAuthor = null,
        public ?string $series = null,
    ) {
    }
}
