<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Идентификатор области перевода. Передаётся в TranslatorInterface для того,
 * чтобы реализации могли вести промежуточный кеш (например, по чанкам) и
 * корректно изолировать состояние между книгами и главами.
 */
final readonly class TranslationScope
{
    public function __construct(
        public string $bookId,
        public string $chapterId,
    ) {
        if ($bookId === '') {
            throw new \InvalidArgumentException('bookId must not be empty');
        }
        if ($chapterId === '') {
            throw new \InvalidArgumentException('chapterId must not be empty');
        }
    }
}
