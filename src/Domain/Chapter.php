<?php

declare(strict_types=1);

namespace App\Domain;

class Chapter
{
    public function __construct(
        private readonly string $id,
        private readonly string $title,
        private readonly string $content,
        private readonly string $relativeFilePath,
        private readonly string $absoluteFilePath,
        private ?string $translatedContent = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }
    public function getTitle(): string
    {
        return $this->title;
    }
    public function getContent(): string
    {
        return $this->content;
    }
    public function getRelativeFilePath(): string
    {
        return $this->relativeFilePath;
    }
    public function getAbsoluteFilePath(): string
    {
        return $this->absoluteFilePath;
    }
    public function getTranslatedContent(): ?string
    {
        return $this->translatedContent;
    }
    public function setTranslatedContent(string $content): void
    {
        $this->translatedContent = $content;
    }
}
