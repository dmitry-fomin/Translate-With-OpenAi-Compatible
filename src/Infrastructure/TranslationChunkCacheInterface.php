<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Кеш промежуточных результатов перевода ПО ЧАНКАМ внутри одной главы.
 * Нужен чтобы при падении в середине длинной главы (битый JSON, обрыв сети
 * и т.п.) при следующем запуске не пере-переводить уже успешные чанки.
 *
 * Ключ: bookId + chapterId + chunkIndex + chunkHash (sha1 содержимого чанка).
 * Значение: перевод чанка плюс снимок running summary и glossary СРАЗУ ПОСЛЕ
 * успешной обработки этого чанка — нужно чтобы при возобновлении правильно
 * восстановить состояние для следующего чанка.
 */
interface TranslationChunkCacheInterface
{
    /**
     * @return null|array{translation: string, summary: string, glossary: array<string, string>}
     */
    public function get(string $bookId, string $chapterId, int $chunkIndex, string $chunkHash): ?array;

    /**
     * @param array<string, string> $glossary
     */
    public function set(
        string $bookId,
        string $chapterId,
        int $chunkIndex,
        string $chunkHash,
        string $translation,
        string $summary,
        array $glossary,
    ): void;
}
