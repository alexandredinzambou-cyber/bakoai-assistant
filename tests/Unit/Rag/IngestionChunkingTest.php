<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\IngestionService;
use ReflectionMethod;
use Tests\TestCase;

class IngestionChunkingTest extends TestCase
{
    public function test_a_short_page_becomes_one_chunk_and_no_heading_is_left_alone(): void
    {
        config(['rag.chunk_size' => 1400]);

        $chunks = $this->chunks(<<<'MD'
# Se connecter

Connectez-vous a votre espace marchand.

## Etapes pour se connecter

### 1. Acceder a la page de connexion

- Rendez-vous sur mypvit.pro/login

### 2. Saisir vos identifiants

- Adresse email et mot de passe
MD);

        $this->assertCount(1, $chunks);
        $this->assertNull($chunks[0]['section']);
        $this->assertStringContainsString("## Etapes pour se connecter\n\n### 1. Acceder a la page de connexion", $chunks[0]['contenu']);
        $this->assertStringContainsString('Adresse email et mot de passe', $chunks[0]['contenu']);
    }

    public function test_sections_are_packed_and_a_code_example_is_never_split_or_reformatted(): void
    {
        config(['rag.chunk_size' => 300]);
        $json = "{\n  \"transactionId\": \"PAY23102400001\",\n  \"status\": \"SUCCESS\",\n".str_repeat("  \"field\": \"valeur d exemple documentee\",\n", 12)."  \"code\": 200\n}";
        $text = "# Webhook\n\nIntroduction du webhook de notification de statut.\n\n"
            ."## Exemple de notification recue\n\n```json\n{$json}\n```\n\n"
            ."## Accuse de reception\n\n### Regles strictes\n\n".str_repeat('Renvoyez le code recu tel quel. ', 12);

        $chunks = $this->chunks($text);
        $withJson = array_values(array_filter($chunks, fn (array $chunk): bool => str_contains($chunk['contenu'], 'PAY23102400001')));

        $this->assertCount(1, $withJson);
        $this->assertStringContainsString("```json\n".$json."\n```", $withJson[0]['contenu']);
        $this->assertSame('Exemple de notification recue', $withJson[0]['section']);

        $rules = array_values(array_filter($chunks, fn (array $chunk): bool => str_contains($chunk['contenu'], 'Renvoyez le code')));
        // A chunk starting in a subsection restates its parent heading, and names its path.
        $this->assertStringStartsWith("## Accuse de reception\n\n### Regles strictes", $rules[0]['contenu']);
        $this->assertSame('Accuse de reception > Regles strictes', $rules[0]['section']);
    }

    public function test_a_long_table_is_split_between_rows_and_each_piece_repeats_the_header(): void
    {
        config(['rag.chunk_size' => 200]);
        $rows = implode("\n", array_map(fn (int $code): string => "| {$code} | ERROR_{$code} | Message documente numero {$code} | 400 |", range(1000, 1011)));
        $chunks = $this->chunks("# Codes d erreur\n\n## Tableau\n\n| Code | Description | Message | HTTP |\n| --- | --- | --- | --- |\n{$rows}");

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertStringContainsString("| Code | Description | Message | HTTP |\n| --- | --- | --- | --- |", $chunk['contenu']);
        }

        $all = implode("\n", array_column($chunks, 'contenu'));

        foreach (range(1000, 1011) as $code) {
            $this->assertSame(1, substr_count($all, "| {$code} | ERROR_{$code}"), "row {$code} must appear exactly once");
        }
    }

    public function test_hidden_streamed_content_is_not_indexed_twice_when_the_body_fallback_is_used(): void
    {
        $html = '<html><body><main></main><div hidden id="S:0"><h2>Gestion du callback</h2>'
            .'<p>Votre serveur doit repondre avec un code HTTP 200 et renvoyer le transactionId recu.</p></div></body></html>';

        $text = $this->invokePrivate('normalizeText', $html, 'html');

        $this->assertSame(1, substr_count($text, 'Votre serveur doit repondre avec un code HTTP 200'));
        $this->assertStringContainsString('## Gestion du callback', $text);
    }

    /**
     * @return list<array{contenu:string, section:?string}>
     */
    private function chunks(string $markdown): array
    {
        return $this->invokePrivate('chunkText', $this->invokePrivate('normalizeText', $markdown, 'md'));
    }

    private function invokePrivate(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(IngestionService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->app->make(IngestionService::class), ...$arguments);
    }
}
