<?php

declare(strict_types=1);

namespace App\Domain;

class CoverImage
{
    private ?string $translatedBytes = null;

    public function __construct(
        private readonly string $bytes,
        private readonly string $mimeType,
        private readonly string $relativeFilePath,
        private readonly string $absoluteFilePath,
    ) {
    }

    public function getBytes(): string
    {
        return $this->bytes;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getRelativeFilePath(): string
    {
        return $this->relativeFilePath;
    }

    public function getAbsoluteFilePath(): string
    {
        return $this->absoluteFilePath;
    }

    public function getTranslatedBytes(): ?string
    {
        return $this->translatedBytes;
    }

    public function setTranslatedBytes(string $bytes): void
    {
        $this->translatedBytes = $bytes;
    }
}
