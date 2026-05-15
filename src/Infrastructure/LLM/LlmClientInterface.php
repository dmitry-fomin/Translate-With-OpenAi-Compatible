<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

interface LlmClientInterface
{
    public function request(string $systemPrompt, string $userPrompt): string;
}
