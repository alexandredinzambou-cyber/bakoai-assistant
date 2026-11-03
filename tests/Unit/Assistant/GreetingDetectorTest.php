<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\GreetingDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GreetingDetectorTest extends TestCase
{
    #[DataProvider('acknowledgements')]
    public function test_a_message_that_only_acknowledges_an_answer_is_an_acknowledgement(string $message): void
    {
        $this->assertTrue((new GreetingDetector)->isAcknowledgement($message));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acknowledgements(): array
    {
        return [
            'real conversation' => ['ok je vois'],
            'ok' => ['ok'],
            'd accord merci' => ["D'accord merci !"],
            'parfait c est clair' => ["parfait, c'est clair"],
            'super merci beaucoup' => ['Super, merci beaucoup'],
            'compris' => ['compris'],
            'ca marche' => ['ça marche'],
            'c est bon' => ["c'est bon"],
            'tres bien' => ['Très bien'],
        ];
    }

    #[DataProvider('questions')]
    public function test_a_message_that_asks_something_is_not_an_acknowledgement(string $message): void
    {
        $this->assertFalse((new GreetingDetector)->isAcknowledgement($message));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function questions(): array
    {
        return [
            'a complaint' => ['ça marche pas'],
            'a follow-up after ok' => ['ok et pour Moov Money ?'],
            'an observation' => ['je vois une erreur 1002'],
            'a question after ok' => ['ok mais comment renouveler le secret ?'],
            'a definition' => ["c'est quoi un callback ?"],
            'words without meaning' => ["c'est"],
        ];
    }
}
