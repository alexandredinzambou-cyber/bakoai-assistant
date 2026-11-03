<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerVerifier;
use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\DurationConversion;
use App\Services\Assistant\JsonExamples;
use App\Services\Assistant\RequestContractAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FifteenthAuditRegressionTest extends TestCase
{
    public static function navigation(): array
    {
        return [
            ['Explique la treizième étape du plan Sandbox.', 'number:13'],
            ['Montre la dix-huitième étape.', 'number:18'],
            ['Affiche la vingt-cinquième étape.', 'number:0'],
            ['Peux-tu passer à l’étape suivante du plan Sandbox ?', 'next'],
            ['Pourriez-vous revenir à l’étape précédente ?', 'previous'],
        ];
    }

    #[DataProvider('navigation')]
    public function test_navigation_variants(string $question, string $expected): void
    {
        $this->assertSame($expected, (new ConversationActions)->stepRequest($question));
    }

    private function contract(): object
    {
        return (object) ['chunk_id' => 582, 'document_titre' => 'Initier un paiement', 'contenu' => "## Paramètres de chemin\n- code-url-payment (string, Obligatoire) — Routage.\n## En-têtes\n- X-Secret (string, Obligatoire) — Authentification.\n## Corps de la requête\n- amount (number, Obligatoire) — Montant.\n- reference (string, Obligatoire) — Référence.\n- agent (string) — Agent associé (optionnel).\n- product (string) — Produit facultatif.\n- free_info (Optionnel) — Information libre.\n- callback_url_code (string) — Code du callback."];
    }

    public function test_description_markers_are_recognized_without_guessing_unmarked_fields(): void
    {
        $answer = (new RequestContractAnswer)->optionalFields('Quels paramètres facultatifs pour le paiement ?', 'Initier un paiement', collect([$this->contract()]))['answer'];
        foreach (['agent', 'product', 'free_info'] as $field) {
            $this->assertStringContainsString('`'.$field.'`', $answer);
        }
        $this->assertStringNotContainsString('callback_url_code', $answer);
        $this->assertStringNotContainsString('amount', $answer);
    }

    public function test_body_scope_excludes_headers_and_path(): void
    {
        $answer = (new RequestContractAnswer)->requiredFields('Liste les paramètres obligatoires du corps pour le paiement, sans les en-têtes ni les paramètres de chemin.', 'Initier un paiement', collect([$this->contract()]))['answer'];
        $this->assertStringContainsString('amount', $answer);
        $this->assertStringNotContainsString('X-Secret', $answer);
        $this->assertStringNotContainsString('code-url-payment', $answer);
    }

    public function test_split_contracts_merge_with_each_fields_source_and_reject_conflicting_statuses(): void
    {
        $body = (object) ['chunk_id' => 582, 'document_titre' => 'Initier un paiement', 'contenu' => "## Corps de la requête\n- amount (number, Obligatoire) — Montant.\n- agent (string) — Agent (optionnel)."];
        $routing = (object) ['chunk_id' => 581, 'document_titre' => 'Initier un paiement', 'contenu' => "## Paramètres de chemin\n- code-url-payment (string, Obligatoire) — Routage.\n## En-têtes\n- X-Secret (string, Obligatoire) — Authentification.\n- X-Callback-MediaType (string) — Média optionnel."];
        $service = new RequestContractAnswer;
        $required = $service->requiredFields('Quels paramètres obligatoires pour le paiement ?', 'Initier un paiement', collect([$body, $routing]));
        $this->assertStringContainsString('amount` : Montant. [SOURCE:582]', $required['answer']);
        $this->assertStringContainsString('X-Secret` : Authentification. [SOURCE:581]', $required['answer']);
        $optional = $service->optionalFields('Quels paramètres optionnels pour le paiement ?', 'Initier un paiement', collect([$body, $routing]));
        $this->assertStringContainsString('agent', $optional['answer']);
        $this->assertStringContainsString('X-Callback-MediaType', $optional['answer']);
        $conflicting = clone $body;
        $conflicting->contenu = str_replace('number, Obligatoire', 'number, Optionnel', $body->contenu);
        $this->assertNull($service->requiredFields('Quels paramètres obligatoires pour le paiement ?', 'Initier un paiement', collect([$body, $conflicting])));
    }

    public function test_query_scope_does_not_confuse_request_parameters_with_the_body(): void
    {
        $chunk = (object) ['chunk_id' => 575, 'document_titre' => 'Obtenir les frais', 'contenu' => "## Paramètres de chemin\n- code-url-fees (string, Obligatoire) — Routage.\n## En-têtes\n- X-Secret (string, Obligatoire) — Clé.\n## Paramètres de requête\n- amount (number, Obligatoire) — Montant."];
        $answer = (new RequestContractAnswer)->requiredFields('Liste uniquement les paramètres de requête obligatoires pour les frais, sans les en-têtes ni les paramètres de chemin.', 'Frais', collect([$chunk]))['answer'];
        $this->assertStringContainsString('amount', $answer);
        $this->assertStringNotContainsString('code-url-fees', $answer);
        $this->assertStringNotContainsString('X-Secret', $answer);
    }

    public function test_combined_formats_keep_every_field(): void
    {
        $actions = new ConversationActions;
        $question = 'Présente les paramètres obligatoires et optionnels de ce paiement dans un tableau.';
        $fact = (new RequestContractAnswer)->combinedFields($question, 'Initier un paiement', collect([$this->contract()]));
        foreach ([$question, 'Résume ensemble les deux groupes de paramètres en deux points.'] as $format) {
            $answer = $actions->formatAnswer($fact['answer'], $format);
            foreach (['amount', 'reference', 'agent', 'product', 'free_info', 'X-Secret', 'code-url-payment'] as $field) {
                $this->assertStringContainsString($field, $answer);
            }
            $this->assertStringContainsString('Obligatoires', $answer);
            $this->assertStringContainsString('Optionnels', $answer);
        }
    }

    public function test_bold_parameter_summaries_and_unlimited_tables_preserve_all_fields(): void
    {
        $source = "Paramètres de paiement :\n";
        foreach (['amount', 'reference', 'service', 'operator_code', 'owner_charge', 'transaction_type', 'customer_account_number', 'merchant_operation_account_code'] as $name) {
            $source .= '- **`'.$name.'`** : Valeur documentée pour ce champ. Exemple documentaire.'."\n";
        }
        foreach (['Résume les paramètres obligatoires en deux points.', 'Présente ces paramètres dans un tableau.'] as $question) {
            $answer = (new ConversationActions)->formatAnswer($source, $question);
            foreach (['amount', 'reference', 'service', 'operator_code', 'owner_charge', 'transaction_type', 'customer_account_number', 'merchant_operation_account_code'] as $name) {
                $this->assertStringContainsString($name, $answer);
            }
        }
    }

    public static function statusQuestions(): array
    {
        return [['Je ne veux pas de statut FAILED. Montre cet exemple JSON avec le statut READY.'], ['Sans statut FAILED ; montre cet exemple JSON avec le statut READY.'], ['Montre cet exemple JSON avec le statut READY, pas le statut FAILED.']];
    }

    #[DataProvider('statusQuestions')]
    public function test_negated_status_does_not_override_positive_request(string $question): void
    {
        $answer = (new JsonExamples)->example("Notification reçue\n```json\n{\"job_id\":\"J\",\"status\":\"READY\"}\n```", $question);
        $this->assertStringContainsString('```json', $answer);
        $this->assertStringContainsString('READY', $answer);
        $this->assertStringNotContainsString('FAILED', $answer);
    }

    public function test_separate_unit_instruction_and_negative_tail(): void
    {
        $this->assertSame(['minute'], (new DurationConversion)->requestedUnits('La clé secrète expire dans combien de temps ? Donne la réponse en minutes.'));
        $this->assertSame(['heure', 'minute'], (new DurationConversion)->requestedUnits('Quand expire le fichier ? Présente le résultat en heures, puis en minutes, pas en secondes.'));
    }

    public function test_negative_only_status_does_not_reuse_the_excluded_payload(): void
    {
        $answer = (new JsonExamples)->example("Notification reçue\n```json\n{\"job_id\":\"J\",\"status\":\"FAILED\"}\n```", 'Sans statut FAILED ; montre cet exemple JSON.');
        $this->assertStringNotContainsString('```', $answer);
        $this->assertStringContainsString('exclusions', $answer);
    }

    public function test_inline_exclusions_do_not_turn_one_parameter_group_into_both(): void
    {
        $this->assertSame('required', (new ConversationActions)->parameterKind('Résume les paramètres obligatoires sans les optionnels.'));
        $this->assertSame('optional', (new ConversationActions)->parameterKind('Présente les paramètres facultatifs sans les obligatoires.'));
    }

    public function test_mixed_plural_header_claim_is_rejected_but_correct_roles_are_kept(): void
    {
        $method = new \ReflectionMethod(AnswerVerifier::class, 'credentialRoleIssue');
        $verifier = new AnswerVerifier;
        $verifier->verify('', collect());
        $this->assertNotNull($method->invoke($verifier, 'La clé secrète X-Secret et le code URL de l’API sont requis dans les en-têtes.'));
        $this->assertNull($method->invoke($verifier, 'Le code URL est un paramètre de chemin. X-Secret est requis dans les en-têtes.'));
        $this->assertNull($method->invoke($verifier, 'Le code URL est un paramètre de chemin et X-Secret est requis dans les en-têtes.'));
    }
}
