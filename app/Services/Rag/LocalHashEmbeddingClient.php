<?php

namespace App\Services\Rag;

class LocalHashEmbeddingClient implements EmbeddingClientInterface
{
    public function embed(string $text, string $inputType = 'document', ?string $title = null): array
    {
        $dimensions = (int) config('embedding.dimensions', 768);
        $vector = array_fill(0, $dimensions, 0.0);
        $tokens = preg_split('/[^\pL\pN]+/u', mb_strtolower($title.' '.$text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 3) {
                continue;
            }

            $index = abs(crc32($token)) % $dimensions;
            $vector[$index] += 1.0;
        }

        return $this->normalize($vector);
    }

    public function model(): string
    {
        return (string) config('embedding.providers.local.model', 'local-hash-v1');
    }

    private function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $vector)));

        if ($norm === 0.0) {
            return $vector;
        }

        return array_map(static fn (float $value): float => round($value / $norm, 6), $vector);
    }
}
