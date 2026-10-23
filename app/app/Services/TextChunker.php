<?php

namespace App\Services;

class TextChunker
{
    public function split(
        string $text,
        int $chunkSize = 1200,
        int $overlap = 200
    ): array {
        $text = trim(
            preg_replace('/\s+/u', ' ', $text) ?? $text
        );

        if ($text === '') {
            return [];
        }

        if ($chunkSize <= 0 || $overlap < 0 || $overlap >= $chunkSize) {
            throw new \InvalidArgumentException(
                'Invalid chunk size or overlap.'
            );
        }

        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;

        while ($start < $length) {
            $chunk = trim(
                mb_substr($text, $start, $chunkSize)
            );

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            if ($start + $chunkSize >= $length) {
                break;
            }

            $start += $chunkSize - $overlap;
        }

        return $chunks;
    }
}
