<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final readonly class OpenAICompatibleClient implements LlmClientInterface
{
    public function __construct(
        private string $apiKey,
        private Client $httpClient,
        private string $apiUrl,
        private string $model,
        private float $temperature = 0.3,
        private ?LlmUsageObserverInterface $usageObserver = null,
        private ?int $maxOutputTokens = null,
    ) {
        if ($this->maxOutputTokens !== null && $this->maxOutputTokens <= 0) {
            throw new \InvalidArgumentException('maxOutputTokens must be > 0 or null');
        }
    }

    /**
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function request(string $systemPrompt, string $userPrompt): string
    {
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $this->temperature,
        ];

        if ($this->maxOutputTokens !== null) {
            $payload['max_tokens'] = $this->maxOutputTokens;
        }

        $request = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ];

        $response = $this->httpClient->post($this->apiUrl, $request);

        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($data['choices'][0]['message']['content']) || !is_string($data['choices'][0]['message']['content'])) {
            throw new \RuntimeException('Unexpected LLM response shape: missing choices[0].message.content');
        }
        $resultContent = $data['choices'][0]['message']['content'];
        if ($resultContent === '') {
            throw new \RuntimeException('LLM returned empty content');
        }

        if ($this->usageObserver !== null) {
            $usage = $data['usage'] ?? null;
            if (is_array($usage)) {
                $inputTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
                $outputTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
                if ($inputTokens > 0 || $outputTokens > 0) {
                    $this->usageObserver->record(new LlmUsage($inputTokens, $outputTokens));
                }
            }
        }

        return $resultContent;
    }
}
