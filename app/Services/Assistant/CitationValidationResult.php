<?php

namespace App\Services\Assistant;

final readonly class CitationValidationResult
{
    /**
     * @param  list<int>  $chunkIds
     */
    private function __construct(
        public bool $valid,
        public string $reason,
        public array $chunkIds = [],
    ) {}

    /**
     * @param  list<int>  $chunkIds
     */
    public static function valid(array $chunkIds): self
    {
        return new self(true, 'valid_citations', $chunkIds);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, $reason);
    }
}
