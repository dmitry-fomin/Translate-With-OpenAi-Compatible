<?php

declare(strict_types=1);

namespace App\Domain;

interface CoverTranslatorInterface
{
    /**
     * Принимает оригинальную обложку и заранее подготовленные русские строки,
     * возвращает байты обложки с заменёнными надписями.
     */
    public function translate(CoverImage $original, CoverTranslationBrief $brief): string;
}
