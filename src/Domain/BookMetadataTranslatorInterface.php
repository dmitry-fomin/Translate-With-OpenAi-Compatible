<?php

declare(strict_types=1);

namespace App\Domain;

interface BookMetadataTranslatorInterface
{
    /**
     * Литературно переводит название книги и транслитерирует имя автора
     * на целевой язык (например, "Empire of the Dawn" → "Империя рассвета",
     * "Jay Kristoff" → "Джей Кристофф").
     */
    public function translate(string $originalTitle, ?string $originalAuthor): BookMetadataTranslation;
}
