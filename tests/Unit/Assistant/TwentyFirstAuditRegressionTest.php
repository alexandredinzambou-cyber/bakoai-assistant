<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\DurationConversion;
use App\Services\Assistant\JsonExamples;
use App\Services\Assistant\ParameterMemory;
use App\Services\Assistant\ParameterScope;
use App\Services\Assistant\RequestContractAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TwentyFirstAuditRegressionTest extends TestCase
{
    public static function polarities(): array
    {
        $cases = [];
        foreach (['optionnels' => ['optional', 'obligatoires'], 'facultatifs' => ['optional', 'obligatoires'], 'obligatoires' => ['required', 'optionnels']] as $word => [$kind, $opposite]) {
            foreach (['pas les', 'sans les', 'ni les'] as $negative) {
                $cases[] = ['Liste les paramètres '.$word.' du corps pour ce paiement, '.$negative.' '.$opposite.'.', $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('polarities')]
    public function test_intent_and_contract_selection_agree_after_negation(string $question, string $kind): void
    {
        $chunks = collect([(object) ['chunk_id' => 1201, 'document_titre' => 'Initier un paiement', 'contenu' => "## Corps de la requête\n- amount (number, Obligatoire) — Montant documenté.\n- agent (string) — Agent documenté (optionnel)."]]);
        $service = new RequestContractAnswer;
        $required = $service->requiredFields($question, 'Initier un paiement', $chunks);
        $optional = $service->optionalFields($question, 'Initier un paiement', $chunks);
        $selected = $required ?? $optional;
        $this->assertSame($kind, (new ConversationActions)->parameterKind($question));
        $this->assertSame($kind, $selected['parameter_kind']);
        $this->assertNull($kind === 'optional' ? $required : $optional);
        $this->assertStringContainsString($kind === 'optional' ? '`agent`' : '`amount`', $selected['answer']);
        $this->assertStringNotContainsString($kind === 'optional' ? '`amount`' : '`agent`', $selected['answer']);
    }

    public static function scopes(): array
    {
        return [['de requête'], ['de la requête'], ['obligatoires de la requête']];
    }

    #[DataProvider('scopes')]
    public function test_query_articles_do_not_inherit_a_previous_location(string $words): void
    {
        $this->assertSame(['query'], (new ParameterScope)->requested('Résume les paramètres '.$words.' des frais.'));
    }

    public static function spokenSteps(): array
    {
        $cases = [];
        foreach (['', 'numéro ', 'n° '] as $label) {
            foreach (['un', 'deux', '1', '2'] as $number) {
                $cases[] = ['Va à l’étape '.$label.'moins '.$number.' du plan Sandbox.'];
            }
        }

        return $cases;
    }

    #[DataProvider('spokenSteps')]
    public function test_spoken_negative_numbers_do_not_rebuild_the_plan(string $question): void
    {
        $this->assertSame('number:0', (new ConversationActions)->stepRequest($question));
    }

    public static function courtesies(): array
    {
        $cases = [];
        foreach (['En ', 'Et en ', 'Convertis cette durée en '] as $prefix) {
            foreach (['stp.', ', s’il te plaît.', ', s’il vous plaît.', 'seulement, s’il te plaît.'] as $suffix) {
                $cases[] = [$prefix.'heures et en minutes '.$suffix];
            }
        }

        return $cases;
    }

    #[DataProvider('courtesies')]
    public function test_courtesy_is_separate_from_unit_completeness(string $question): void
    {
        $this->assertSame(['heure', 'minute'], (new DurationConversion)->requestedUnits($question));
    }

    public static function unsupported(): array
    {
        return [['En heures et en semaines.'], ['Et en heures et en semaines.'], ['Convertis cette durée en heures et en semaines.'], ['Convertis la durée en mois.']];
    }

    #[DataProvider('unsupported')]
    public function test_all_conversion_forms_reject_partial_unit_lists(string $question): void
    {
        $service = new DurationConversion;
        $this->assertTrue($service->isConversionFollowUp($question));
        $this->assertSame([], $service->requestedUnits($question));
    }

    public function test_except_status_excludes_the_documented_value_without_fabrication(): void
    {
        $source = "Notification reçue\n```json\n{\"job_id\":\"A\",\"status\":\"READY\"}\n```";
        $answer = (new JsonExamples)->example($source, 'Tout sauf le statut READY : montre cet exemple JSON.');
        $this->assertStringNotContainsString('```', $answer);
        $this->assertStringContainsString('Aucun exemple JSON vérifié', $answer);
    }

    public function test_repair_removes_bad_aliases_and_keeps_other_facts_and_valid_groups(): void
    {
        $bad = ['answer' => "Corps de la requête — champs obligatoires :\n- `amount` : Montant documenté.", 'sources' => []];
        $good = ['answer' => '- `agent` : Agent optionnel.', 'sources' => []];
        $memory = ['last_question' => 'Paramètres facultatifs du remboursement', 'last_answer' => $bad['answer'], 'duration_fact' => ['value' => 7, 'unit' => 'jour'], 'parameter_sets' => ['optional' => $bad, 'optional:body' => $bad, 'required:body' => $bad], 'subjects' => ['payment' => ['parameter_sets' => ['optional:body' => $good]]]];
        $fixed = (new ParameterMemory)->repair($memory);
        $this->assertSame(['required:body'], array_keys($fixed['parameter_sets']));
        $this->assertTrue($fixed['parameter_memory_invalidated']);
        $this->assertSame('', $fixed['last_answer']);
        $this->assertSame($memory['duration_fact'], $fixed['duration_fact']);
        $this->assertSame($good, $fixed['subjects']['payment']['parameter_sets']['optional:body']);
        $this->assertSame($fixed, (new ParameterMemory)->repair($fixed));
    }

    public function test_descriptive_mentions_are_not_treated_as_contradictory_headings(): void
    {
        $this->assertFalse((new ParameterMemory)->contradicts('required', 'Les paramètres optionnels ne sont pas décrits ici.'));
        $this->assertTrue((new ParameterMemory)->contradicts('optional', 'Corps — champs obligatoires [1] :'));
    }

    public function test_malformed_old_entries_do_not_break_unrelated_memory(): void
    {
        $memory = ['duration_fact' => ['value' => 7, 'unit' => 'jour'], 'subjects' => ['broken' => 'invalid', 'export' => ['topic_question' => 'Export']], 'parameter_sets' => ['optional' => 'invalid', 'required' => ['answer' => ['invalid']]]];
        $fixed = (new ParameterMemory)->repair($memory);
        $this->assertSame([], $fixed['parameter_sets']);
        $this->assertArrayNotHasKey('broken', $fixed['subjects']);
        $this->assertSame($memory['duration_fact'], $fixed['duration_fact']);
        $this->assertSame($memory['subjects']['export'], $fixed['subjects']['export']);
    }

    public function test_contract_metadata_and_combined_group_contradictions_are_rejected(): void
    {
        $good = ['answer' => 'Champs obligatoires : `amount` : Montant.', 'contract_kind' => 'required', 'sources' => []];
        $memory = ['parameter_sets' => ['required:body' => $good, 'optional:body' => ['answer' => '`agent` : Agent.', 'contract_kind' => 'required'], 'both:body' => ['answer' => "### Obligatoires\nChamps obligatoires :\n- `amount` : Montant.\n\n### Optionnels\nCorps — champs obligatoires :\n- `amount` : Montant.", 'contract_kind' => 'both']]];
        $fixed = (new ParameterMemory)->repair($memory);
        $this->assertSame(['required:body' => $good], $fixed['parameter_sets']);
        $this->assertTrue($fixed['parameter_memory_invalidated']);
    }
}
