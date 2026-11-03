<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\ConversationMemory;
use App\Services\Assistant\DurationConversion;
use App\Services\Assistant\FollowUpDetector;
use App\Services\Assistant\JsonExamples;
use App\Services\Assistant\ParameterScope;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeventeenthAuditRegressionTest extends TestCase
{
    public static function invalidSteps(): array
    {
        return [['−1'], ['–1'], ['—1'], ['－1'], ['− 1']];
    }

    #[DataProvider('invalidSteps')]
    public function test_unicode_negative_steps_are_rejected(string $number): void
    {
        $this->assertSame('number:0', (new ConversationActions)->stepRequest('Va à l’étape '.$number.' du plan Sandbox.'));
    }

    public static function politeNavigation(): array
    {
        return [['S’il te plaît, peux-tu me montrer l’étape précédente du plan Sandbox ?', 'previous'], ['Pourriez-vous nous afficher la quatrième étape ?', 'number:4'], ['S’il vous plaît, pourriez-vous passer à l’étape suivante ?', 'next']];
    }

    #[DataProvider('politeNavigation')]
    public function test_politeness_can_be_composed(string $question, string $expected): void
    {
        $this->assertSame($expected, (new ConversationActions)->stepRequest($question));
    }

    public static function bareUnits(): array
    {
        return [['En secondes uniquement.', 'seconde'], ['En heures uniquement.', 'heure'], ['En minutes seulement.', 'minute'], ['En jours.', 'jour']];
    }

    #[DataProvider('bareUnits')]
    public function test_bare_unit_requests_refer_to_the_current_fact(string $question, string $unit): void
    {
        $this->assertSame([$unit], (new DurationConversion)->requestedUnits($question));
        $this->assertTrue((new FollowUpDetector)->isFollowUp($question, 'Durée de disponibilité de l’export'));
        $this->assertFalse((new FollowUpDetector)->isFollowUp('Autre sujet : '.$question, 'Durée de l’export'));
    }

    public static function scopes(): array
    {
        return [
            ['Paramètres obligatoires du corps, sans les en-têtes ni les paramètres de chemin.', ['body']],
            ['Résume uniquement les en-têtes obligatoires, sans les champs du corps.', ['headers']],
            ['Paramètres de requête obligatoires pour les frais.', ['query']],
            ['Les paramètres de chemin et les en-têtes.', ['path', 'headers']],
            ['Tous les paramètres, sans les en-têtes.', ['body', 'path', 'query']],
            ['Les deux groupes de paramètres.', null],
            ['Tous les paramètres obligatoires.', ['body', 'path', 'query', 'headers']],
        ];
    }

    #[DataProvider('scopes')]
    public function test_parameter_locations_are_distinct_from_kind(string $question, ?array $expected): void
    {
        $this->assertSame($expected, (new ParameterScope)->requested($question));
    }

    public static function negativeStatus(): array
    {
        return [['Montre cet exemple JSON, mais pas avec le statut READY. Utilise uniquement un autre statut documenté.'], ['Montre cet exemple JSON sans le statut READY.'], ['Je ne veux pas de statut READY. Montre cet exemple JSON.']];
    }

    #[DataProvider('negativeStatus')]
    public function test_a_negated_status_cannot_become_a_positive_constraint(string $question): void
    {
        $source = "Notification reçue\n```json\n{\"job_id\":\"J\",\"status\":\"READY\"}\n```";
        $answer = (new JsonExamples)->example($source, $question);
        $this->assertStringNotContainsString('```', $answer);
        $this->assertStringContainsString('Aucun exemple JSON vérifié', $answer);
    }

    public function test_single_sentence_combines_all_groups_without_a_list(): void
    {
        $answer = (new ConversationActions)->formatAnswer("### Obligatoires\n- `amount` : Montant documenté.\n- `reference` : Référence documentée.\n\n### Optionnels\n- `agent` : Agent documenté (optionnel).", 'Résume les deux groupes de paramètres en une seule phrase, sans tableau ni liste.');
        foreach (['amount', 'reference', 'agent'] as $field) {
            $this->assertStringContainsString($field, $answer);
        }
        $this->assertSame(0, preg_match_all('/^\d+\./mu', $answer));
        $this->assertStringNotContainsString("\n", $answer);
        $this->assertSame(1, substr_count($answer, '.'));
        $this->assertStringNotContainsString('; ;', $answer);
        $this->assertStringNotContainsString(';.', $answer);
    }

    public function test_an_exhaustive_limited_table_groups_the_remaining_fields(): void
    {
        $source = "- `amount` : Montant documenté.\n- `reference` : Référence documentée.\n- `service` : Service documenté.\n- `operator_code` : Opérateur documenté.";
        $answer = (new ConversationActions)->formatAnswer($source, 'Présente les paramètres dans un tableau de deux lignes, sans rien omettre.');
        foreach (['amount', 'reference', 'service', 'operator_code'] as $field) {
            $this->assertStringContainsString($field, $answer);
        }
        $this->assertCount(4, explode("\n", $answer));
    }

    public function test_combined_parameter_summaries_remove_the_procedure_example_without_flattening_code(): void
    {
        $source = "### Obligatoires\n1. Préparez les champs :\n- `amount` : Montant documenté.\n2. Envoyez la requête :\n```bash\ncurl --data '{\"agent\":\"example\"}'\n```\n\n### Optionnels\n- `agent` : Agent optionnel.";
        $answer = (new ConversationActions)->formatAnswer($source, 'Résume les deux groupes de paramètres en deux points.');
        $this->assertStringContainsString('`amount`', $answer);
        $this->assertStringContainsString('`agent`', $answer);
        $this->assertStringNotContainsString('curl', $answer);
        $this->assertStringNotContainsString('```', $answer);
        $this->assertSame(2, preg_match_all('/^\d+\./mu', $answer));
    }

    public function test_scope_variants_are_cleared_on_invalidation_and_legacy_subject_restore(): void
    {
        $memory = ['topic_question' => 'Paiement', 'active_parameter_scope' => 'headers', 'parameter_sets' => ['required:body' => ['answer' => 'Body', 'sources' => []], 'required:headers' => ['answer' => 'Headers', 'sources' => []]], 'subjects' => ['callback' => ['topic_question' => 'Callback', 'last_answer' => 'Notification documentée.']]];
        $service = new ConversationMemory;
        foreach ([$service->invalidate($memory), $service->forQuestion($memory, 'Reviens au callback.')] as $cleared) {
            $this->assertNull($cleared['active_parameter_scope']);
            $this->assertSame([], $cleared['parameter_sets']);
        }
    }

    public function test_full_contract_summary_preserves_hyphenated_headers_and_path_fields(): void
    {
        $answer = (new ConversationActions)->formatAnswer("- `code-url-payment` : Code de routage documenté.\n- `X-Secret` : Clé d’authentification documentée.\n- `amount` : Montant documenté.\n- `reference` : Référence documentée.", 'Résume tous les paramètres obligatoires en deux points.');
        foreach (['code-url-payment', 'X-Secret', 'amount', 'reference'] as $field) {
            $this->assertStringContainsString($field, $answer);
        }
        $this->assertSame(2, preg_match_all('/^\d+\./mu', $answer));
    }
}
