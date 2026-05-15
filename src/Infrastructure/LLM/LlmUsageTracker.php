<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

final class LlmUsageTracker implements LlmUsageObserverInterface
{
    private int $totalInputTokens = 0;
    private int $totalOutputTokens = 0;
    private int $requestCount = 0;
    private int $lastInputTokens = 0;
    private int $lastOutputTokens = 0;

    /** @var (callable(LlmUsage): void)|null */
    private $onRecord = null;

    public function record(LlmUsage $usage): void
    {
        $this->lastInputTokens = $usage->inputTokens;
        $this->lastOutputTokens = $usage->outputTokens;
        $this->totalInputTokens += $usage->inputTokens;
        $this->totalOutputTokens += $usage->outputTokens;
        ++$this->requestCount;

        if ($this->onRecord !== null) {
            ($this->onRecord)($usage);
        }
    }

    /**
     * @param (callable(LlmUsage): void)|null $callback
     */
    public function setOnRecord(?callable $callback): void
    {
        $this->onRecord = $callback;
    }

    public function reset(): void
    {
        $this->totalInputTokens = 0;
        $this->totalOutputTokens = 0;
        $this->requestCount = 0;
        $this->lastInputTokens = 0;
        $this->lastOutputTokens = 0;
    }

    public function getTotalInputTokens(): int
    {
        return $this->totalInputTokens;
    }

    public function getTotalOutputTokens(): int
    {
        return $this->totalOutputTokens;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function getLastInputTokens(): int
    {
        return $this->lastInputTokens;
    }

    public function getLastOutputTokens(): int
    {
        return $this->lastOutputTokens;
    }
}
