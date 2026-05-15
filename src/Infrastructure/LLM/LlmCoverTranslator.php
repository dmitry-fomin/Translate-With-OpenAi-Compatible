<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

use App\Domain\CoverImage;
use App\Domain\CoverTranslationBrief;
use App\Domain\CoverTranslatorInterface;
use GuzzleHttp\Client;

final readonly class LlmCoverTranslator implements CoverTranslatorInterface
{
    public function __construct(
        private string $apiKey,
        private Client $httpClient,
        private string $apiUrl,
        private string $model,
        private string $systemPrompt,
    ) {
    }

    public function translate(CoverImage $original, CoverTranslationBrief $brief): string
    {
        $dataUrl = 'data:' . $original->getMimeType() . ';base64,' . base64_encode($original->getBytes());

        [$width, $height] = $this->detectDimensions($original->getBytes());
        $userText = $this->buildUserText($brief, $width, $height);

        $request = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $userText],
                            ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                        ],
                    ],
                ],
                'modalities' => ['image', 'text'],
            ],
        ];

        $response = $this->httpClient->post($this->apiUrl, $request);
        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        return $this->extractImageBytes($data);
    }

    /**
     * @param int<1, max>|null $width
     * @param int<1, max>|null $height
     */
    private function buildUserText(CoverTranslationBrief $brief, ?int $width, ?int $height): string
    {
        $lines = [
            'Replace text on this book cover with the EXACT strings below. Do NOT translate by yourself, do NOT paraphrase — use these strings verbatim.',
            '',
            sprintf('- Title (replace "%s"): "%s"', $brief->originalTitle, $brief->translatedTitle),
        ];

        if ($brief->authorOriginal !== null && $brief->authorTranslated !== null) {
            $lines[] = sprintf('- Author (replace "%s"): "%s"', $brief->authorOriginal, $brief->authorTranslated);
        } elseif ($brief->authorTranslated !== null) {
            $lines[] = sprintf('- Author: "%s"', $brief->authorTranslated);
        }

        if ($brief->series !== null) {
            $lines[] = sprintf('- Series mark: "%s"', $brief->series);
        }

        $lines[] = '';
        $lines[] = 'Keep artwork, layout, colors, fonts, kerning, decorative elements identical. Replace text only.';

        if ($width !== null && $height !== null) {
            $ratio = $this->formatAspectRatio($width, $height);
            $lines[] = sprintf(
                'Output image MUST have the same dimensions as the source: %dx%d pixels (aspect ratio %s, portrait book cover). Do NOT crop, do NOT pad, do NOT return a square image.',
                $width,
                $height,
                $ratio,
            );
        } else {
            $lines[] = 'Output image MUST keep the original aspect ratio and resolution (portrait book cover). Do NOT crop, do NOT pad, do NOT return a square image.';
        }
        $lines[] = 'No frames, no mockups, no collages.';

        return implode("\n", $lines);
    }

    /**
     * @return array{0: int<1, max>|null, 1: int<1, max>|null}
     */
    private function detectDimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return [null, null];
        }
        $w = $info[0];
        $h = $info[1];
        if ($w < 1 || $h < 1) {
            return [null, null];
        }
        return [$w, $h];
    }

    private function formatAspectRatio(int $width, int $height): string
    {
        $gcd = $this->gcd($width, $height);
        $w = intdiv($width, $gcd);
        $h = intdiv($height, $gcd);

        // Если сокращённые числа слишком большие — отдаём десятичное отношение.
        if ($w > 50 || $h > 50) {
            return sprintf('%.3f:1', $width / $height);
        }

        return sprintf('%d:%d', $w, $h);
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a > 0 ? $a : 1;
    }

    /**
     * @param array<mixed> $data
     */
    private function extractImageBytes(array $data): string
    {
        $message = $data['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \RuntimeException('Cover LLM response: missing choices[0].message');
        }

        // OpenRouter / closerouter: message.images[0].image_url.url = data URL
        if (isset($message['images']) && is_array($message['images'])) {
            foreach ($message['images'] as $img) {
                $url = $img['image_url']['url'] ?? null;
                if (is_string($url)) {
                    $bytes = $this->decodeDataUrl($url);
                    if ($bytes !== null) {
                        return $bytes;
                    }
                }
            }
        }

        // Иногда возвращают content как массив multimodal частей
        if (isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $url = $part['image_url']['url'] ?? null;
                if (is_string($url)) {
                    $bytes = $this->decodeDataUrl($url);
                    if ($bytes !== null) {
                        return $bytes;
                    }
                }
                if (($part['type'] ?? null) === 'output_image' && isset($part['data']) && is_string($part['data'])) {
                    $decoded = base64_decode($part['data'], true);
                    if ($decoded !== false && $decoded !== '') {
                        return $decoded;
                    }
                }
            }
        }

        // Иногда content — строка с data URL внутри
        if (isset($message['content']) && is_string($message['content'])) {
            if (preg_match('#data:image/[^;]+;base64,([A-Za-z0-9+/=]+)#', $message['content'], $m) === 1) {
                $decoded = base64_decode($m[1], true);
                if ($decoded !== false && $decoded !== '') {
                    return $decoded;
                }
            }
        }

        throw new \RuntimeException('Cover LLM response: no image payload found in response');
    }

    private function decodeDataUrl(string $url): ?string
    {
        if (preg_match('#^data:[^;]+;base64,(.+)$#', $url, $m) !== 1) {
            return null;
        }
        $decoded = base64_decode($m[1], true);
        if ($decoded === false || $decoded === '') {
            return null;
        }
        return $decoded;
    }
}
