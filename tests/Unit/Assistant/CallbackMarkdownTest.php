<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerVerifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CallbackMarkdownTest extends TestCase
{
    private function sources(): Collection
    {
        return collect([(object) ['chunk_id' => 647, 'document_titre' => 'Webhook', 'section' => 'Accusé de réception', 'contenu' => "Votre serveur répond HTTP 200 OK avec un écho du transactionId et du code reçus.\n```json\n{\"transactionId\":\"PAY23102400001\",\"responseCode\":200}\n```"]]);
    }

    public static function fences(): array
    {
        return [['```', ''], ['~~~', ''], ['```', '   '], ['~~~', '   '], ['```', '     '], ['~~~', '     ']];
    }

    #[DataProvider('fences')]
    public function test_numbered_rendering_closes_the_example_before_the_following_step(string $fence, string $indent): void
    {
        $answer = "1. Recevez la notification.\n\n2. Renvoyez cet accusé :\n\n".$indent.$fence."json\n".$indent."{\n".$indent.'  "transactionId":"PAY23102400001",'."\n".$indent.'  "responseCode":200'."\n".$indent."}\n".$fence." [SOURCE:647]\n\n3. Conservez les valeurs reçues [SOURCE:647].";
        $rendered = (new AnswerVerifier)->renderNumbered($answer, $this->sources());
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.Str::markdown($rendered['answer']));
        $xpath = new \DOMXPath($dom);
        $blocks = $xpath->query('//pre/code');
        $this->assertCount(1, $blocks);
        $this->assertSame(['transactionId' => 'PAY23102400001', 'responseCode' => 200], json_decode($blocks->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Conservez', $blocks->item(0)->textContent);
        $this->assertStringContainsString('Conservez les valeurs', $dom->textContent);
        $this->assertDoesNotMatchRegularExpression('/^[ \t]*(?:```|~~~)[ \t]*\[/m', $rendered['answer']);
    }

    public function test_json_comment_cleanup_preserves_the_list_indentation_and_continuation(): void
    {
        $answer = "1. Répondez HTTP 200 OK [SOURCE:647].\n\n2. Renvoyez cet accusé :\n\n     ```json\n     {\n       \"transactionId\":\"PAY23102400001\", // exemple documentaire\n       \"responseCode\":200\n     }\n     ```\n     [SOURCE:647]\n\n3. Votre serveur répond HTTP 200 OK [SOURCE:647].";
        $verifier = new AnswerVerifier;
        $verified = $verifier->verify($answer, $this->sources());
        $this->assertTrue($verified->acceptable);
        $this->assertStringContainsString('     ```json', $verified->answer);
        $this->assertStringContainsString("\n     ```", $verified->answer);
        $this->assertStringNotContainsString('// exemple', $verified->answer);
        $this->assertStringContainsString('3. Votre serveur', $verified->answer);
        $rendered = $verifier->renderNumbered($verified->answer, $this->sources());
        $html = Str::markdown($rendered['answer']);
        $this->assertSame(1, substr_count($html, '<pre>'));
    }

    public function test_a_standalone_indented_example_remains_one_valid_json_block(): void
    {
        $answer = "     ```json\n     {\"transactionId\":\"PAY23102400001\",\"responseCode\":200}\n     ```\n[SOURCE:647]";
        $rendered = (new AnswerVerifier)->renderNumbered($answer, $this->sources());
        $dom = new \DOMDocument;
        @$dom->loadHTML(Str::markdown($rendered['answer']));
        $blocks = (new \DOMXPath($dom))->query('//pre/code');
        $this->assertCount(1, $blocks);
        $this->assertSame(200, json_decode($blocks->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['responseCode']);
    }

    public function test_prose_after_a_closing_fence_and_citation_is_outside_the_example(): void
    {
        $answer = "```json\n{\"transactionId\":\"PAY23102400001\",\"responseCode\":200}\n``` [SOURCE:647] (valeurs documentaires).\n\nLa suite de la procédure.";
        $rendered = (new AnswerVerifier)->renderNumbered($answer, $this->sources());
        $dom = new \DOMDocument;
        @$dom->loadHTML(Str::markdown($rendered['answer']));
        $blocks = (new \DOMXPath($dom))->query('//pre/code');
        $this->assertCount(1, $blocks);
        $this->assertSame(200, json_decode($blocks->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['responseCode']);
        $this->assertStringNotContainsString('valeurs documentaires', $blocks->item(0)->textContent);
        $this->assertStringContainsString('valeurs documentaires', $dom->textContent);
    }
}
