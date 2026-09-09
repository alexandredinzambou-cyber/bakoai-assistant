<?php

namespace App\Services\Rag;

interface EmbeddingClientInterface
{
    public function embed(string $text, string $inputType = 'document', ?string $title = null): array;

    public function model(): string;
}
