<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class CoverTranslationBrief
{
    public function __construct(
        public string $originalTitle,
        public string $translatedTitle,
        public ?string $authorOriginal = null,
        public ?string $authorTranslated = null,
        public ?string $series = null,
    ) {
        if (trim($originalTitle) === '') {
            throw new \InvalidArgumentException('CoverTranslationBrief: originalTitle must not be empty');
        }
        if (trim($translatedTitle) === '') {
            throw new \InvalidArgumentException('CoverTranslationBrief: translatedTitle must not be empty');
        }
    }
}
