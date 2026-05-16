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
        $orientation = $this->detectOrientation($width, $height);
        $userText = $this->buildUserText($brief, $orientation);

        $json = [
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
        ];

        // Размер задаётся отдельным параметром API — это рекомендация гайда OpenAI
        // (https://developers.openai.com/cookbook/examples/multimodal/image-gen-models-prompting-guide):
        // модель игнорирует пиксели в тексте промпта, но уважает `size` как aspect-ratio подсказку.
        // Стандартные пресеты gpt-image-2: 1024x1024 / 1024x1536 / 1536x1024.
        $size = $this->pickSize($orientation);
        if ($size !== null) {
            $json['size'] = $size;
        }

        $request = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $json,
        ];

        $response = $this->httpClient->post($this->apiUrl, $request);
        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        $bytes = $this->extractImageBytes($data);

        if ($width !== null && $height !== null) {
            $bytes = $this->resizeToOriginal($bytes, $width, $height, $original->getMimeType()) ?? $bytes;
        }

        return $bytes;
    }

    /**
     * Ужимаем ответ модели к пиксельным размерам исходной обложки. gpt-image-2 отдаёт
     * портрет в 1568×2352 (~4.5 MB), что раздувает EPUB. Сохраняем в исходном MIME,
     * иначе ломается соответствие manifest ↔ файл при перепаковке.
     *
     * @param int<1, max> $targetWidth
     * @param int<1, max> $targetHeight
     */
    private function resizeToOriginal(string $bytes, int $targetWidth, int $targetHeight, string $mimeType): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= $targetWidth && $srcH <= $targetHeight) {
            imagedestroy($src);
            return null;
        }

        $scale = min($targetWidth / $srcW, $targetHeight / $srcH);
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        // Дефолтный режим (IMG_BILINEAR_FIXED) — единственный, который гарантированно
        // есть во всех сборках GD; IMG_BICUBIC в части бинарников macOS возвращает false.
        $dst = @imagescale($src, $dstW, $dstH);
        imagedestroy($src);
        if ($dst === false) {
            return null;
        }

        ob_start();
        $ok = match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => imagejpeg($dst, null, 90),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dst, null, 90) : false,
            default => imagepng($dst, null, 9),
        };
        $resized = ob_get_clean();
        imagedestroy($dst);

        if ($ok === false || !is_string($resized) || $resized === '') {
            return null;
        }
        return $resized;
    }

    private function buildUserText(CoverTranslationBrief $brief, string $orientation): string
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
        $lines[] = match ($orientation) {
            'portrait' => 'This is a portrait book cover. Keep portrait orientation.',
            'landscape' => 'This is a landscape book cover. Keep landscape orientation.',
            default => 'Keep the original aspect ratio of the cover.',
        };
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

    /**
     * @param int<1, max>|null $width
     * @param int<1, max>|null $height
     */
    private function detectOrientation(?int $width, ?int $height): string
    {
        if ($width === null || $height === null) {
            return 'portrait';
        }
        $ratio = $width / $height;
        return match (true) {
            $ratio < 0.95 => 'portrait',
            $ratio > 1.05 => 'landscape',
            default => 'square',
        };
    }

    private function pickSize(string $orientation): ?string
    {
        return match ($orientation) {
            'portrait' => '1024x1536',
            'landscape' => '1536x1024',
            'square' => '1024x1024',
            default => null,
        };
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
