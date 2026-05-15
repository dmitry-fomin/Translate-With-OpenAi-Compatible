<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

final readonly class LlmUsage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
    ) {
    }
}
