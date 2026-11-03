<?php

namespace App\Services\Assistant;

/**
 * Outcome of AnswerVerifier::verify(): the generated answer with only its detected errors
 * removed, what was removed and why, and whether what remains still answers the question.
 */
final class VerifiedAnswer
{
    /**
     * @param  list<array{text: string, reason: string, detail: string}>  $removed
     * @param  list<int>  $citedIds  Context chunk ids the kept answer cites, in order of first use.
     * @param  list<int>  $supportingIds  Chunks the kept answer relies on: the cited ones, or, for
     *                                    an answer without citations, the chunks it matches best.
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $removed,
        public readonly int $keptUnits,
        public readonly int $substantiveUnits,
        public readonly bool $acceptable,
        public readonly ?string $reason,
        public readonly array $citedIds,
        public readonly array $supportingIds,
    ) {}

    public function hasRemovals(): bool
    {
        return $this->removed !== [];
    }

    public function removedShare(): float
    {
        return $this->substantiveUnits > 0 ? ($this->substantiveUnits - $this->keptUnits) / $this->substantiveUnits : 0.0;
    }

    // An acceptable answer beats one that is not; between two of the same kind, the one that kept
    // more content and lost less of what it said wins.
    public function isBetterThan(self $other): bool
    {
        if ($this->acceptable !== $other->acceptable) {
            return $this->acceptable;
        }

        if ($this->keptUnits !== $other->keptUnits) {
            return $this->keptUnits > $other->keptUnits;
        }

        return $this->removedShare() < $other->removedShare();
    }

    /**
     * @return list<string>
     */
    public function removedReasons(): array
    {
        return array_values(array_unique(array_column($this->removed, 'reason')));
    }
}
