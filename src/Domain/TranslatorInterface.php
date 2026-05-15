<?php

declare(strict_types=1);

namespace App\Domain;

interface TranslatorInterface
{
    public function translate(string $text, string $context = '', ?TranslationScope $scope = null): string;
}
