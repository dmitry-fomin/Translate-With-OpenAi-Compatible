<?php

declare(strict_types=1);

namespace App\Domain;

class Book
{
    private ?CoverImage $cover = null;

    /** @param Chapter[] $chapters */
    public function __construct(
        private readonly string $title,
        private array $chapters = [],
        private readonly ?string $author = null,
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }
    public function getAuthor(): ?string
    {
        return $this->author;
    }
    public function getChapters(): array
    {
        return $this->chapters;
    }
    public function addChapter(Chapter $chapter): void
    {
        $this->chapters[] = $chapter;
    }
    public function getCover(): ?CoverImage
    {
        return $this->cover;
    }
    public function setCover(CoverImage $cover): void
    {
        $this->cover = $cover;
    }
}
