<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\SummarizerInterface;
use App\Infrastructure\LLM\LlmClientInterface;

readonly class LlmSummarizer implements SummarizerInterface
{
    public function __construct(
        private LlmClientInterface $llmClient,
        private string $summarizePrompt = 'Create a very short summary in English of the following chapter. This summary will be used as context for translating the next chapter.',
        private TextSplitter $splitter = new TextSplitter(),
        private int $maxChunkSize = 15000,
    ) {
    }

    public function summarize(string $text, string $previousSummary = ''): string
    {
        $systemPrompt = $this->summarizePrompt;
        if ($previousSummary) {
            $systemPrompt .= "\nSummary of previous chapters:\n" . $previousSummary . "\nCombine them and make a new concise summary.";
        }

        $chunks = $this->splitter->split($text, $this->maxChunkSize);
        $textToSummarize = $chunks[0];
        if (count($chunks) > 1) {
            $textToSummarize = $text;
        }

        return $this->llmClient->request($systemPrompt, "Chapter text:\n" . $textToSummarize);
    }
}
