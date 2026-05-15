<?php

declare(strict_types=1);

namespace App\Infrastructure;

class TextSplitter
{
    /**
     * Закрывающие блочные теги xhtml, на которых безопасно разрезать главу.
     * Порядок не важен — ищем все совпадения.
     */
    private const BLOCK_CLOSE_REGEX = '#</(?:p|div|h[1-6]|li|blockquote|pre|ul|ol|section|article|figure|table|tr|aside)>#i';

    /**
     * Разрезает xhtml-главу на чанки, не превышающие $maxSizeBytes, по границам
     * закрывающих блочных тегов. Если в окне нет ни одного блочного закрытия —
     * fallback на последний `\n`, затем — жёсткое отсечение по размеру.
     *
     * Объединение всех чанков обязано побайтово равняться исходному тексту.
     *
     * @return string[]
     */
    public function splitXhtml(string $text, int $maxSizeBytes = 8000): array
    {
        if ($maxSizeBytes <= 0) {
            throw new \InvalidArgumentException('maxSizeBytes must be > 0');
        }

        $totalLength = strlen($text);
        if ($totalLength <= $maxSizeBytes) {
            return [$text];
        }

        $chunks = [];
        $pos = 0;

        while ($pos < $totalLength) {
            $remaining = $totalLength - $pos;
            if ($remaining <= $maxSizeBytes) {
                $chunks[] = substr($text, $pos);
                break;
            }

            $window = substr($text, $pos, $maxSizeBytes);

            $cutSize = $this->findLastBlockClose($window);
            if ($cutSize === null) {
                $lastNewline = strrpos($window, "\n");
                $cutSize = $lastNewline !== false ? $lastNewline + 1 : $maxSizeBytes;
            }

            $chunks[] = substr($text, $pos, $cutSize);
            $pos += $cutSize;
        }

        return $chunks;
    }

    /**
     * Возвращает длину префикса окна, заканчивающегося последним закрывающим
     * блочным тегом, либо null если такого нет.
     */
    private function findLastBlockClose(string $window): ?int
    {
        if (preg_match_all(self::BLOCK_CLOSE_REGEX, $window, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        if ($matches[0] === []) {
            return null;
        }
        /** @var array{0: string, 1: int} $last */
        $last = end($matches[0]);
        return $last[1] + strlen($last[0]);
    }

    /**
     * Splits text into chunks of maximum size, trying to split at newlines.
     *
     * @param string $text
     * @param int $maxSizeBytes
     * @return string[]
     */
    public function split(string $text, int $maxSizeBytes = 15000): array
    {
        if (strlen($text) <= $maxSizeBytes) {
            return [$text];
        }

        $chunks = [];
        $currentPos = 0;
        $totalLength = strlen($text);

        while ($currentPos < $totalLength) {
            $remainingLength = $totalLength - $currentPos;

            if ($remainingLength <= $maxSizeBytes) {
                $chunks[] = substr($text, $currentPos);
                break;
            }

            // Take a chunk of max size
            $chunk = substr($text, $currentPos, $maxSizeBytes);

            // Try to find the last newline in this chunk to split cleanly
            $lastNewline = strrpos($chunk, "\n");

            if ($lastNewline !== false) {
                // Split at the newline (include it in the current chunk)
                $chunkSize = $lastNewline + 1;
                $chunks[] = substr($text, $currentPos, $chunkSize);
                $currentPos += $chunkSize;
            } else {
                // No newline found, split at max size
                $chunks[] = $chunk;
                $currentPos += $maxSizeBytes;
            }
        }

        return $chunks;
    }
}
