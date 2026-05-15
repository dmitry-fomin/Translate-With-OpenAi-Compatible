<?php

declare(strict_types=1);

namespace App\Domain;

interface SummarizerInterface
{
    public function summarize(string $text, string $previousSummary = ''): string;
}
