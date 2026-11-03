<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\FollowUpDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpansionRequestTest extends TestCase
{
    public static function requests(): array
    {
        return array_map(static fn ($text) => [$text], ['plus en détail', 'Plus en détails stp', 'Encore plus en détail', 'Développe', 'Développe cette étape.', 'Détaille ce point', 'Explique-moi davantage', 'Peux-tu développer ?', 'Peux-tu m’expliquer davantage ?', 'Explique cette étape plus en détail', 'Peux-tu expliquer davantage les callbacks ?']);
    }

    #[DataProvider('requests')]
    public function test_only_subjectless_expansions_reuse_the_previous_topic(string $question): void
    {
        $expected = $question !== 'Peux-tu expliquer davantage les callbacks ?';
        $this->assertSame($expected, (new ConversationActions)->requestsExpansion($question));
        if ($expected) {
            $this->assertTrue((new FollowUpDetector)->isFollowUp($question, 'expliques moi le 3'));
            $this->assertFalse((new FollowUpDetector)->isFollowUp($question));
        }
    }
}
