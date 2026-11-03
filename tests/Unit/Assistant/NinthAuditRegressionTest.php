<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\ConversationMemory;
use App\Services\Assistant\ConversationPlan;
use App\Services\Assistant\DurationConversion;
use App\Services\Assistant\JsonExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NinthAuditRegressionTest extends TestCase
{
    public static function groupedDurations(): array
    {
        return [['3 600 secondes'], ["3\u{00A0}600 secondes"], ["3\u{202F}600 secondes"], ['3600 secondes']];
    }

    #[DataProvider('groupedDurations')]
    public function test_grouped_numbers_are_read_in_full(string $text): void
    {
        $service = new DurationConversion;
        $this->assertStringContainsString('60 minutes', $service->convert($service->extract($text), 'minute'));
    }

    public function test_malformed_grouping_cannot_become_a_shorter_duration(): void
    {
        $this->assertNull((new DurationConversion)->extract('La validité est de 3 60 secondes.'));
        $this->assertSame('heure', (new DurationConversion)->requestedUnit('Rappelle la durée de la clé secrète en heures.'));
        $this->assertSame('minute', (new DurationConversion)->requestedUnit('Quelle validité pour la clé en minutes ?'));
    }

    public function test_single_objects_are_never_substituted_for_another_role(): void
    {
        $json = new JsonExamples;
        $ack = "Accusé à renvoyer :\n```json\n{\"transactionId\":\"A\",\"responseCode\":200}\n```";
        $incoming = "Notification reçue :\n```json\n{\"transactionId\":\"A\",\"status\":\"SUCCESS\",\"amount\":150,\"code\":200}\n```";
        $this->assertSame([], $json->select($json->extract($ack), 'Les champs de la notification reçue'));
        $this->assertSame([], $json->select($json->extract($incoming), 'Les champs de l’accusé à renvoyer'));
        $both = $json->extract($ack."\n".$incoming);
        $this->assertStringNotContainsString('responseCode', $json->fields($both, 'Les champs de la notification, pas de l’accusé.'));
        $this->assertStringNotContainsString('amount', $json->fields($both, 'Pas la notification, mais les champs de l’accusé.'));
    }

    public function test_echo_explanation_does_not_turn_an_acknowledgement_into_an_incoming_notification(): void
    {
        $text = "Votre serveur répond avec transactionId et le code de la notification reçue.\n```json\n{\"transactionId\":\"A\",\"responseCode\":200}\n```";
        $json = new JsonExamples;
        $this->assertSame('ack', $json->extract($text)[0]['role']);
        $this->assertSame([], $json->select($json->extract($text), 'Champs de la notification entrante'));
        $legacy = [['role' => 'incoming', 'keys' => ['transactionId', 'responseCode'], 'block' => 0]];
        $this->assertSame([], $json->select($legacy, 'Champs de la notification entrante'));
        $this->assertCount(1, $json->select($legacy, 'Champs de l’accusé'));
    }

    public function test_distinct_json_values_stay_ambiguous_but_common_field_names_are_safe(): void
    {
        $text = "Notification reçue :\n```json\n{\"transactionId\":\"A\",\"status\":\"SUCCESS\"}\n```\nNotification reçue :\n```json\n{\"transactionId\":\"B\",\"status\":\"FAILED\"}\n```";
        $json = new JsonExamples;
        $this->assertCount(2, $json->extract($text));
        $this->assertStringNotContainsString('```', $json->example($text, 'Exemple de notification'));
        $this->assertSame('`transactionId`, `status`.', $json->fields($json->extract($text), 'Champs de notification'));
    }

    public function test_an_earlier_callback_section_does_not_relabel_a_credentials_response(): void
    {
        $text = "## Webhook de notification\nConfigurez le callback.\n## Authentification\n### Réponse attendue\n```json\n{\"secret\":\"example\",\"expires_in\":3600}\n```";
        $json = new JsonExamples;
        $this->assertSame('response', $json->extract($text)[0]['role']);
        $this->assertSame([], $json->select($json->extract($text), 'Champs de notification entrante'));
    }

    public function test_production_is_a_distinct_procedure_and_old_sandbox_snapshot_is_restored(): void
    {
        $plan = new ConversationPlan;
        $this->assertSame('production', $plan->procedureSubject('Va à l’étape 2 du passage en production.'));
        $this->assertSame('sandbox', $plan->subject('Reviens au plan Sandbox à l’étape 4.'));
        $memory = ['topic_question' => 'Frais', 'subjects' => ['payment' => ['topic_question' => 'Paiements Sandbox', 'plan_subject' => 'sandbox', 'plan_steps' => ['Un', 'Deux']]]];
        $restored = (new ConversationMemory)->forQuestion($memory, 'Reviens au plan Sandbox à l’étape 2.');
        $this->assertSame(['Un', 'Deux'], $restored['plan_steps']);
    }

    public function test_two_steps_preserve_the_final_required_action(): void
    {
        $answer = "1. Ouvrez la configuration.\n2. Saisissez une URL publique.\n3. Soumettez le formulaire [1].";
        $formatted = (new ConversationActions)->formatAnswer($answer, 'Résume la procédure de callback en deux étapes.');
        $this->assertSame(2, preg_match_all('/^\d+\. /mu', $formatted));
        $this->assertStringContainsString('Soumettez', $formatted);
        $this->assertStringContainsString('[1]', $formatted);
    }
}
