<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\ConversationMemory;
use App\Services\Assistant\DurationConversion;
use App\Services\Assistant\JsonExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ThirteenthAuditRegressionTest extends TestCase
{
    public static function steps(): array
    {
        return [['Explique simplement la quatrième étape du plan Sandbox.', 'number:4'], ['Détaille la troisième étape.', 'number:3'], ['Montre la 4e étape.', 'number:4'], ['Explique l’étape 4.', 'number:4'], ['Passe à l’étape suivante de la procédure de remboursement.', 'next'], ['Donne la première étape.', 'first'], ['Affiche la douzième étape.', 'number:12']];
    }

    #[DataProvider('steps')]
    public function test_step_variants_select_one_saved_step(string $question, string $expected): void
    {
        $this->assertSame($expected, (new ConversationActions)->stepRequest($question));
    }

    public function test_descriptive_or_invalid_numbers_do_not_select_another_step(): void
    {
        $actions = new ConversationActions;
        $this->assertNull($actions->stepRequest('Pourquoi le journal mentionne-t-il la quatrième étape ?'));
        $this->assertSame('number:0', $actions->stepRequest('Explique l’étape -1.'));
        $this->assertSame('number:0', $actions->stepRequest('Montre l’étape 2.5.'));
    }

    public static function conversions(): array
    {
        return [['Combien de minutes la clé secrète reste-t-elle valide ?', ['minute']], ['En combien de secondes expire la clé ?', ['seconde']], ['Exprime ce délai en heures, puis en minutes.', ['heure', 'minute']], ['Exprime cette durée en heures puis en minutes et en secondes.', ['heure', 'minute', 'seconde']], ['Et en heures, pas en minutes.', ['heure']]];
    }

    #[DataProvider('conversions')]
    public function test_direct_questions_and_sequential_units_are_complete(string $question, array $expected): void
    {
        $this->assertSame($expected, (new DurationConversion)->requestedUnits($question));
    }

    public static function reuseRequests(): array
    {
        return [['Montre un exemple JSON de cette notification.'], ['Pas la notification entrante ; montre un exemple JSON de l’accusé à renvoyer.'], ['Montre cet exemple JSON avec le statut FAILED.']];
    }

    #[DataProvider('reuseRequests')]
    public function test_documentary_reuse_is_recognized(string $question): void
    {
        $this->assertTrue((new ConversationActions)->protocolExampleRequest($question));
    }

    public function test_a_requested_status_is_not_replaced_with_another_value(): void
    {
        $source = "Notification reçue\n```json\n{\"job_id\":\"J1\",\"status\":\"SUCCESS\"}\n```";
        $json = new JsonExamples;
        foreach (['FAILED', 'failed'] as $status) {
            $answer = $json->example($source, 'Montre cet exemple JSON avec le statut '.$status.'.');
            $this->assertStringNotContainsString('```', $answer);
            $this->assertStringContainsString('FAILED', $answer);
        }
        $source .= "\nNotification reçue\n```json\n{\"job_id\":\"J2\",\"status\":\"FAILED\"}\n```";
        $answer = $json->example($source, 'Pas le statut SUCCESS, mais montre cet exemple JSON avec le statut FAILED.');
        $this->assertStringContainsString('"J2"', $answer);
        $this->assertStringNotContainsString('SUCCESS', $answer);
    }

    public function test_negating_an_acknowledgement_still_selects_the_incoming_example(): void
    {
        $source = "Notification reçue\n```json\n{\"transactionId\":\"A\",\"status\":\"SUCCESS\",\"code\":200}\n```\nAccusé\n```json\n{\"transactionId\":\"A\",\"responseCode\":200}\n```";
        $answer = (new JsonExamples)->example($source, 'Pas l’accusé ; montre un exemple JSON de cette notification.');
        $this->assertStringContainsString('"status"', $answer);
        $this->assertStringNotContainsString('responseCode', $answer);
    }

    public function test_parameter_kind_respects_an_explicit_negative_tail(): void
    {
        $actions = new ConversationActions;
        $this->assertSame('required', $actions->parameterKind('Résume les obligatoires, pas les optionnels.'));
        $this->assertSame('optional', $actions->parameterKind('Résume les optionnels, sans les obligatoires.'));
    }

    public function test_invalidation_and_legacy_restore_clear_typed_parameter_sets(): void
    {
        $service = new ConversationMemory;
        $memory = ['topic_question' => 'Frais', 'active_parameter_kind' => 'required', 'parameter_sets' => ['required' => ['answer' => 'Old', 'sources' => []]], 'subjects' => ['callback' => ['topic_question' => 'Callback', 'last_answer' => 'Callback.']]];
        $this->assertSame([], $service->invalidate($memory)['parameter_sets']);
        $restored = $service->forQuestion($memory, 'Reviens au callback.');
        $this->assertSame([], $restored['parameter_sets']);
        $this->assertNull($restored['active_parameter_kind']);
    }

    public function test_status_inspection_requires_one_verified_value(): void
    {
        $json = new JsonExamples;
        $this->assertStringContainsString('statut unique', $json->status('Aucun exemple.', 'Quel statut contient cet exemple ?'));
        $source = "Notification reçue\n```json\n{\"status\":\"SUCCESS\",\"job_id\":\"A\"}\n```\nNotification reçue\n```json\n{\"status\":\"FAILED\",\"job_id\":\"B\"}\n```";
        $this->assertStringContainsString('statut unique', $json->status($source, 'Quel statut contient cet exemple ?'));
    }
}
