<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerVerifier;
use App\Services\Assistant\ConversationMemory;
use App\Services\Assistant\ConversationPlan;
use App\Services\Assistant\DocumentedFactAnswer;
use App\Services\Assistant\DurationConversion;
use App\Services\Assistant\FollowUpDetector;
use App\Services\Assistant\JsonExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EleventhAuditRegressionTest extends TestCase
{
    public static function continuations(): array
    {
        return [['Résume ce parcours en deux étapes sans perdre les actions obligatoires.'], ['Rappelle cette durée en heures.'], ['Quels paramètres sont obligatoires ?'], ['Et les paramètres optionnels, uniquement si la documentation en indique ?']];
    }

    #[DataProvider('continuations')]
    public function test_natural_continuations_keep_the_subject(string $question): void
    {
        $this->assertTrue((new FollowUpDetector)->isFollowUp($question, 'Parcours de paiement Sandbox et frais.'));
    }

    public function test_an_explicit_other_contract_is_not_a_bare_parameter_followup(): void
    {
        $this->assertFalse((new FollowUpDetector)->isFollowUp('Autre sujet : quels paramètres sont obligatoires pour un remboursement ?', 'Frais'));
        $this->assertSame('reporting', (new ConversationPlan)->subject('Non, je parle du webhook de reporting des exports, pas du callback de paiement.'));
    }

    public static function roleNegations(): array
    {
        return [['Je ne veux pas les champs de l’accusé de réception. Donne les noms des champs de la notification entrante.', 'incoming'], ['Sans notification, donne les noms des champs de l’accusé.', 'ack'], ['Pas de réponse de mon serveur ; liste les champs de la notification.', 'incoming'], ['Sans code PHP, donne les noms des champs de la notification.', 'incoming'], ['Sans code PHP, uniquement les noms des champs de la notification.', 'incoming']];
    }

    #[DataProvider('roleNegations')]
    public function test_negated_roles_do_not_override_the_positive_request(string $question, string $role): void
    {
        $examples = [['role' => 'ack', 'keys' => ['transactionId', 'responseCode']], ['role' => 'incoming', 'keys' => ['transactionId', 'status', 'code']]];
        $selected = (new JsonExamples)->select($examples, $question);
        $this->assertCount(1, $selected);
        $this->assertSame($role, $selected[0]['role']);
    }

    public function test_multiple_units_and_the_original_start_event_are_kept(): void
    {
        $service = new DurationConversion;
        $targets = $service->requestedUnits('Et en minutes et en secondes ?');
        $this->assertSame(['minute', 'seconde'], $targets);
        $text = $service->convertMany(['value' => 7, 'unit' => 'jour'], $targets);
        $this->assertStringContainsString('10080 minutes', $text);
        $this->assertStringContainsString('604800 secondes', $text);
        $this->assertStringContainsString('point de départ', $text);
        $this->assertSame(['heure'], $service->requestedUnits('Rappelle la durée en heures, pas en secondes.'));
    }

    public static function unsupportedNumbers(): array
    {
        return [['-3600 secondes'], ['1e3 secondes'], ['2E+3 secondes'], ['+3600 secondes'], ['3 60 secondes']];
    }

    #[DataProvider('unsupportedNumbers')]
    public function test_unsupported_numbers_are_rejected_whole(string $text): void
    {
        $this->assertNull((new DurationConversion)->extract('La durée est de '.$text.'.'));
    }

    public function test_legacy_restore_drops_facts_from_another_subject(): void
    {
        $memory = ['topic_question' => 'Clé secrète', 'last_question' => 'Quelle clé ?', 'last_sources' => [['chunk_id' => 1]], 'last_operation' => 'secret', 'duration_fact' => ['value' => 3600, 'unit' => 'seconde'], 'payload_answer' => 'Old secret', 'subjects' => ['callback' => ['topic_question' => 'Callback', 'last_answer' => 'Configurez votre URL.']]];
        $restored = (new ConversationMemory)->forQuestion($memory, 'Reviens au callback');
        $this->assertNull($restored['duration_fact']);
        $this->assertSame('', $restored['payload_answer']);
        $this->assertSame('Configurez votre URL.', $restored['last_verified_answer']);
        $this->assertSame('Callback', $restored['last_question']);
        $this->assertSame([], $restored['last_sources']);
        $this->assertNull($restored['last_operation']);
    }

    public function test_recently_used_subject_is_not_evicted_before_older_subjects(): void
    {
        $service = new ConversationMemory;
        $memory = [];
        foreach (['frais', 'exports', 'callback', 'clé', 'remboursement', 'solde', 'KYC', 'opérateurs', 'statut', 'paiement', 'frais', 'plan Sandbox'] as $subject) {
            $memory = $service->retain($memory, ['topic_question' => $subject, 'last_question' => $subject, 'last_answer' => 'Vérifié.', 'last_sources' => []], true, false);
        }
        $this->assertCount(10, $memory['subjects']);
        $this->assertArrayHasKey('fees', $memory['subjects']);
        $this->assertArrayNotHasKey('export', $memory['subjects']);
    }

    public function test_mandatory_json_field_count_is_checked_even_in_a_lead_in(): void
    {
        $source = (object) ['chunk_id' => 645, 'document_titre' => 'Webhook de reporting', 'contenu' => "Répondez 2xx.\n### Ce que votre endpoint doit répondre\n```json\n{\"received\":true}\n```"];
        $service = new AnswerVerifier;
        $wrong = $service->verify('La documentation exige un objet JSON avec deux champs obligatoires pour accuser réception de la notification de reporting [SOURCE:645] :', collect([$source]));
        $this->assertContains('protocol_contradiction', $wrong->removedReasons());
        $valid = $service->verify('Le JSON de l’accusé de reporting contient un champ obligatoire [SOURCE:645].', collect([$source]));
        $this->assertNotContains('protocol_contradiction', $valid->removedReasons());
        $negative = $service->verify('L’accusé JSON de reporting ne nécessite pas deux champs obligatoires [SOURCE:645].', collect([$source]));
        $this->assertNotContains('protocol_contradiction', $negative->removedReasons());
        $unrelated = $service->verify('Pour ne pas perdre la notification de reporting, renvoyez deux champs obligatoires dans le JSON [SOURCE:645].', collect([$source]));
        $this->assertContains('protocol_contradiction', $unrelated->removedReasons());
    }

    public function test_an_interrupted_inline_example_invalidates_the_answer_instead_of_leaving_a_partial_list(): void
    {
        $source = (object) ['chunk_id' => 643, 'contenu' => 'Les champs row_count et finished_at indiquent le nombre de lignes et la date de fin.'];
        $answer = "Les champs sont :\n- `row_count` : nombre de lignes [SOURCE:643].\n- `finished_at` : date de fin, ex. `\"2026-08-";
        $result = (new AnswerVerifier)->verify($answer, collect([$source]));
        $this->assertFalse($result->acceptable);
        $this->assertContains('incomplete_answer', $result->removedReasons());
    }

    public function test_a_negated_other_contract_does_not_override_the_positive_subject(): void
    {
        $question = 'Liste les noms des champs de la notification du callback de paiement, pas du reporting.';
        $this->assertSame('callback', (new ConversationPlan)->subject($question));
        $chunks = collect([(object) ['chunk_id' => 1, 'document_titre' => 'Webhook paiement', 'section' => 'Notification', 'contenu' => "```json\n{\"transactionId\":\"A\",\"status\":\"SUCCESS\",\"code\":200}\n```"], (object) ['chunk_id' => 2, 'document_titre' => 'Webhook de reporting', 'section' => 'Notification', 'contenu' => "Notification reçue\n```json\n{\"event\":\"ready\",\"job_id\":\"J\"}\n```"]]);
        $fact = (new DocumentedFactAnswer)->jsonRoleFields($question, $question, $chunks);
        $this->assertNotNull($fact);
        $this->assertStringContainsString('transactionId', $fact['answer']);
        $this->assertStringNotContainsString('job_id', $fact['answer']);
    }

    public function test_optional_json_keys_are_not_counted_as_mandatory(): void
    {
        $source = (object) ['chunk_id' => 1, 'contenu' => "- alpha (Obligatoire) — Identifiant.\n- beta (Obligatoire) — Type.\n- note (Optionnel) — Note.\n### Exemple de requête\n```json\n{\"alpha\":\"A\",\"beta\":\"B\",\"note\":\"N\"}\n```"];
        $service = new AnswerVerifier;
        $valid = $service->verify('La requête JSON contient deux champs obligatoires [SOURCE:1].', collect([$source]));
        $this->assertNotContains('protocol_contradiction', $valid->removedReasons());
        $invalid = $service->verify('La requête JSON contient trois champs obligatoires [SOURCE:1].', collect([$source]));
        $this->assertContains('protocol_contradiction', $invalid->removedReasons());
    }
}
