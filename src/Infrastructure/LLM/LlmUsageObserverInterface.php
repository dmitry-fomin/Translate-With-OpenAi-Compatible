<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

interface LlmUsageObserverInterface
{
    public function record(LlmUsage $usage): void;
}
