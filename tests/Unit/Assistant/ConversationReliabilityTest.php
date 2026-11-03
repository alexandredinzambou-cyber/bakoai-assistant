<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerVerifier;
use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\ConversationMemory;
use App\Services\Assistant\ConversationPlan;
use App\Services\Assistant\DocumentedFactAnswer;
use App\Services\Assistant\ResponseGuard;
use Tests\TestCase;

class ConversationReliabilityTest extends TestCase
{
    public function test_existing_tables_are_parsed_as_facts_instead_of_nested_headers(): void
    {
        $actions = new ConversationActions;
        $table = "| Code callback | Clé secrète |\n| --- | --- |\n| Routage callback | Identifie l’URL pour les notifications [1]. |\n| X-Secret | Authentifie les appels avec une clé secrète [2]. |";
        $result = $actions->formatAnswer($table, 'Présente ces deux éléments dans un tableau');
        $this->assertCount(4, explode("\n", $result));
        $this->assertStringNotContainsString('\\|', $result);
        $this->assertStringContainsString('Authentifie', $result);
        $plain = $actions->formatAnswer($result, 'Retire le tableau et garde seulement deux phrases, sans code.');
        $this->assertStringNotContainsString('|', $plain);
        $this->assertStringContainsString('Authentifie', $plain);
    }

    public function test_navigation_variants_and_step_simplification_are_recognized(): void
    {
        $actions = new ConversationActions;
        $this->assertSame('first', $actions->stepRequest('Donne-moi uniquement la première étape.'));
        $this->assertSame('previous', $actions->stepRequest('Reviens à l’étape précédente.'));
        $this->assertSame(['kind' => 'sentences', 'count' => 1], $actions->formatRequest('Attends, explique cette étape en une seule phrase simple.'));
        foreach (['Retire le tableau et garde seulement deux phrases, sans code.', 'Résume seulement ces paramètres en trois points.', 'Compare le code callback et X-Secret dans un tableau de deux lignes.'] as $question) {
            $this->assertTrue($actions->refersToPreviousAnswer($question));
        }
    }

    public function test_documentary_acknowledgement_is_copied_and_keeps_dynamic_echo_rules(): void
    {
        $source = (object) ['chunk_id' => 647, 'contenu' => "## Accusé de réception attendu de votre serveur\nVotre serveur répond HTTP 200 OK avec un écho du transactionId et du code reçus.\n```json\n{\"transactionId\":\"PAY123\",\"responseCode\":200}\n```"];
        $fact = (new DocumentedFactAnswer)->callbackAcknowledgement('Quel JSON mon serveur doit-il renvoyer pour accuser réception du callback ?', collect([$source]));
        $this->assertStringContainsString('ne les codez pas en dur', $fact['answer']);
        $this->assertStringContainsString('"responseCode":200', $fact['answer']);
        $source->contenu = str_replace('responseCode', 'received', $source->contenu);
        $this->assertNull((new DocumentedFactAnswer)->callbackAcknowledgement('Quel JSON pour accuser réception du callback ?', collect([$source])));
    }

    public function test_refusals_do_not_replace_useful_facts_and_subject_snapshots_are_bounded(): void
    {
        $service = new ConversationMemory;
        $fees = ['topic_question' => 'API de frais', 'last_answer' => 'GET /fees', 'last_sources' => []];
        $memory = $service->retain([], $fees, true, false);
        $this->assertSame($memory, $service->retain($memory, ['last_answer' => 'Refus'], false, false));
        $memory = $service->retain($memory, ['topic_question' => 'Clé secrète', 'last_answer' => '3600 secondes', 'last_sources' => []], true, false);
        $restored = $service->forQuestion($memory, 'Revenons aux frais');
        $this->assertSame('GET /fees', $restored['last_verified_answer']);
        $this->assertArrayHasKey('secret', $memory['subjects']);
    }

    public function test_sandbox_requirements_cannot_be_downgraded_to_optional(): void
    {
        $source = (object) ['chunk_id' => 614, 'document_titre' => 'Guide Sandbox', 'section' => 'APIs complémentaires requises', 'contenu' => 'Afin de valider définitivement votre intégration, les API KYC et Check Status sont obligatoires.'];
        $facts = new DocumentedFactAnswer;
        $this->assertStringContainsString('obligatoires', $facts->sandboxRequirements('Quels prérequis pour tester en Sandbox ?', collect([$source]))['answer']);
        $this->assertNull($facts->sandboxRequirements('Quelle durée pour la clé ?', collect([$source])));
        $result = (new AnswerVerifier)->verify('Check Status est optionnel pour valider votre intégration [SOURCE:614].', collect([$source]));
        $this->assertContains('protocol_contradiction', $result->removedReasons());
    }

    public function test_sandbox_plan_requires_all_documented_sections_and_keeps_their_citations(): void
    {
        $texts = [
            "### Code du Compte d’Opération\n- Récupérez le Compte TEST.\n### Token d’API\n- Définissez le mot de passe.",
            "## 1. Authentification\nGénérez la clé secrète.\n### Exemple de requête\n```bash\ncurl example\n```",
            '## 2. Simulation de Transaction : Scénario Succès Effectuez deux simulations de succès.',
            'Echo dynamique : reprenez le code reçu. Condition de validation des tests : traitez le callback.',
            '## 4. Simulation de Transaction : Scénario Échec Effectuez deux simulations en échec.',
            '## 5. APIs Complémentaires Requises Afin de valider définitivement votre intégration KYC et Check Status sont obligatoires.',
        ];
        $chunks = collect($texts)->map(static fn ($text, $index) => (object) ['chunk_id' => $index + 1, 'document_titre' => 'Guide Sandbox', 'contenu' => $text]);
        $facts = new DocumentedFactAnswer;
        $plan = $facts->sandboxPlan('Quels prérequis et étapes en Sandbox ?', $chunks);
        $this->assertCount(6, (new ConversationPlan)->extract($plan['answer']));
        $this->assertStringContainsString('obligatoires. [SOURCE:6]', $plan['answer']);
        $this->assertStringNotContainsString('curl', $plan['answer']);
        $this->assertNull($facts->sandboxPlan('Quels prérequis et étapes en Sandbox ?', $chunks->take(5)));
    }

    public function test_callback_roles_need_both_routing_and_authentication_evidence(): void
    {
        $routing = (object) ['chunk_id' => 582, 'contenu' => 'callback_url_code (string) — Code de l’URL de callback pour les notifications.'];
        $secret = (object) ['chunk_id' => 588, 'contenu' => 'Les appels sont authentifiés via l’en-tête X-Secret.'];
        $facts = new DocumentedFactAnswer;
        $question = 'Ce code authentifie mes appels dans X-Secret ?';
        $fact = $facts->callbackRoles($question, 'Comment déclarer le callback ?', collect([$routing, $secret]));
        $this->assertStringContainsString('ne remplace pas la clé secrète', $fact['answer']);
        $this->assertCount(2, $fact['chunks']);
        $this->assertNull($facts->callbackRoles($question, 'Comment déclarer le callback ?', collect([$routing])));
    }

    public function test_a_get_fees_example_explains_query_parameters_without_inventing_a_json_body(): void
    {
        $source = (object) ['chunk_id' => 590, 'document_titre' => 'Obtenir les frais d’une transaction', 'contenu' => "Endpoint : GET /{code-url-fees}/get-fees\n## Paramètres de requête\namount est obligatoire.\n```bash\ncurl 'https://api.mypvit.pro/v2/demo/get-fees?amount=150'\n```"];
        $facts = new DocumentedFactAnswer;
        $fact = $facts->feesQueryExample('Et un exemple de requête JSON valide ?', 'Quels paramètres pour les frais ?', collect([$source]));
        $this->assertStringContainsString('ne définit pas de corps JSON', $fact['answer']);
        $this->assertStringContainsString('get-fees?amount=150', $fact['answer']);
        $this->assertNull($facts->feesQueryExample('Un exemple JSON ?', 'Configurer le callback', collect([$source])));
    }

    public function test_parameter_abbreviations_do_not_truncate_examples_in_a_summary(): void
    {
        $answer = "- `accountOperationCode` (compte marchand)\n- `transactionType` (type, ex. PAYMENT)\n- `amount` (montant)\n- `operatorCode` (opérateur, ex. AIRTEL_MONEY)";
        $summary = (new ConversationActions)->formatAnswer($answer, 'Résume ces paramètres en trois points.');
        $this->assertStringContainsString('ex. PAYMENT)', $summary);
        $this->assertStringContainsString('ex. AIRTEL_MONEY)', $summary);
        $this->assertSame(3, preg_match_all('/^\d+\. /m', $summary));
    }

    public function test_negative_support_actions_leave_the_information_request_intact(): void
    {
        $actions = new ConversationActions;
        foreach (['Sans contacter le support, donne-moi seulement l’endpoint GET pour vérifier les frais.', 'Non, je veux seulement consulter les frais, pas ouvrir de ticket.'] as $question) {
            $this->assertTrue($actions->declinesSupport($question));
            $this->assertFalse($actions->requestsSupport($question));
            $query = $actions->withoutSupportDirective($question);
            $this->assertStringContainsString('frais', $query);
            $this->assertStringNotContainsString('ticket', $query);
            $this->assertStringNotContainsString('support', $query);
        }
    }

    public function test_table_conversion_contains_two_complete_rows_with_identifier_labels(): void
    {
        $actions = new ConversationActions;
        $request = 'Peux-tu présenter ces deux éléments dans un petit tableau ?';
        $this->assertTrue($actions->refersToPreviousAnswer($request));
        $table = $actions->formatAnswer("`X-Secret` authentifie les appels API [1].\n\n`callback_url_code` identifie l’URL de notification [2].", $request);
        $this->assertCount(4, explode("\n", $table));
        $this->assertStringContainsString('| X-Secret |', $table);
        $this->assertStringContainsString('| callback_url_code |', $table);
    }

    public function test_an_empty_table_is_never_accepted_as_a_documented_answer(): void
    {
        $result = (new AnswerVerifier)->verify("Voici le tableau demandé :\n\n| Élément | Description |", collect());
        $this->assertFalse($result->acceptable);
        $this->assertSame('incomplete_table', $result->reason);
    }

    public function test_routing_authentication_confusion_is_detected_across_sentences(): void
    {
        $source = (object) ['chunk_id' => 607, 'document_titre' => 'Guide Sandbox', 'section' => 'Callback', 'contenu' => 'Un code URL est associé à l’URL de callback. La clé X-Secret authentifie les requêtes API. Le Code URL (API Secret) concerne le renouvellement.'];
        $bad = (new AnswerVerifier)->verify('PVIT génère un code URL (API Secret) associé à cette URL de callback. Ce code sera nécessaire pour authentifier les requêtes de callback ultérieures [SOURCE:607].', collect([$source]));
        $this->assertFalse($bad->acceptable);
        $this->assertContains('protocol_contradiction', $bad->removedReasons());
        $good = (new AnswerVerifier)->verify('La clé X-Secret authentifie les requêtes API. Un code URL est associé à l’URL de callback [SOURCE:607].', collect([$source]));
        $this->assertTrue($good->acceptable, json_encode($good->removed));
    }

    public function test_negation_in_one_clause_does_not_exempt_a_positive_authentication_claim_after_mais(): void
    {
        $chunk = (object) ['chunk_id' => 607, 'document_titre' => 'Guide Sandbox', 'section' => 'Callback', 'contenu' => 'Le code URL identifie le callback. La clé X-Secret authentifie les requêtes. Le Code URL (API Secret) concerne le renouvellement.'];
        $answer = 'Le code URL (API Secret) n’est pas généré lors de l’ajout de l’URL de callback, mais est nécessaire pour authentifier les requêtes de callback via l’en-tête X-Secret [SOURCE:607].';
        $result = (new AnswerVerifier)->verify($answer, collect([$chunk]));
        $this->assertFalse($result->acceptable);
        $this->assertContains('protocol_contradiction', $result->removedReasons());
    }

    public function test_export_expiration_uses_the_request_anchor_instead_of_generation(): void
    {
        $chunk = (object) ['chunk_id' => 592, 'document_titre' => 'Télécharger un export', 'section' => 'Export', 'contenu' => '410 : le fichier a expiré. Le fichier reste disponible 7 jours après la demande.'];
        $bad = (new AnswerVerifier)->verify('Le fichier d’export a expiré après 7 jours depuis sa génération [SOURCE:592].', collect([$chunk]));
        $this->assertContains('protocol_contradiction', $bad->removedReasons());
        $fact = (new DocumentedFactAnswer)->expiredExport('Que signifie HTTP 410 pour cet export ?', 'Export de transactions', collect([$chunk]));
        $this->assertStringContainsString('7 jours après la demande', $fact['answer']);
        $bad = (new AnswerVerifier)->verify('Le fichier reste disponible 7 jours après la demande, celle du téléchargement [SOURCE:592].', collect([$chunk]));
        $this->assertContains('protocol_contradiction', $bad->removedReasons());
        $fact = (new DocumentedFactAnswer)->exportRenewal('Si je redemande cet export, son ancien lien est-il prolongé ?', 'Télécharger un export', collect([$chunk]));
        $this->assertStringContainsString('ne peux donc pas la confirmer', $fact['answer']);
    }

    public function test_inline_documented_paths_are_not_classified_as_application_code(): void
    {
        $guard = new ResponseGuard;
        $this->assertFalse($guard->containsSourceCode('La méthode est `GET /{code-url-fees}/get-fees`.'));
        $this->assertTrue($guard->containsSourceCode('`function fees() { return 1; }`'));
    }

    public function test_plan_extraction_keeps_order_and_examples_inside_their_step(): void
    {
        $steps = (new ConversationPlan)->extract("1. Créez votre compte de test [1].\n\n2. Configurez le callback [2].\n```json\n{\"code\":200}\n```\n\n3. Testez une transaction Sandbox [3].");
        $this->assertCount(3, $steps);
        $this->assertStringContainsString('Créez', $steps[0]);
        $this->assertStringContainsString('callback', $steps[1]);
        $this->assertStringContainsString('```json', $steps[1]);
        $this->assertStringContainsString('{"code":200}', $steps[1]);
    }

    public function test_endpoint_synonyms_remain_grounded_in_the_active_subject(): void
    {
        $chunk = (object) ['chunk_id' => 575, 'document_titre' => 'Obtenir les frais', 'contenu' => 'Endpoint : GET /{code-url-fees}/get-fees'];
        $fact = (new DocumentedFactAnswer)->endpoint('Seulement la méthode HTTP et le chemin.', 'API de frais', collect([$chunk]));
        $this->assertSame('GET /{code-url-fees}/get-fees [SOURCE:575].', $fact['answer']);
    }

    public function test_generic_request_verbs_do_not_make_a_neighbour_endpoint_look_equally_relevant(): void
    {
        $chunks = collect([
            (object) ['chunk_id' => 575, 'document_titre' => 'Obtenir les frais', 'contenu' => 'Endpoint : GET /{code-url-fees}/get-fees'],
            (object) ['chunk_id' => 580, 'document_titre' => 'Vérification client', 'contenu' => 'Endpoint : POST /kyc'],
        ]);
        $request = 'Donne-moi seulement l’endpoint GET pour vérifier les frais.';
        $fact = (new DocumentedFactAnswer)->endpoint($request, $request, $chunks);
        $this->assertSame('GET /{code-url-fees}/get-fees [SOURCE:575].', $fact['answer']);
    }

    public function test_credential_lifetime_can_be_answered_after_an_export_duration_question(): void
    {
        $chunk = (object) ['chunk_id' => 588, 'contenu' => '{"secret":"example","expires_in":3600}'];
        $fact = (new DocumentedFactAnswer)->credentialDuration('Et la clé secrète ?', 'Combien de temps le fichier est-il conservé ?', collect([$chunk]));
        $this->assertSame('La clé secrète reste valide 3600 secondes après sa génération [SOURCE:588].', $fact['answer']);
    }

    public function test_unlisted_payment_names_are_checked_against_the_documentation_too(): void
    {
        $chunk = (object) ['chunk_id' => 1, 'document_titre' => 'PVIT', 'section' => 'Paiements', 'contenu' => 'Les moyens de paiement documentés incluent Airtel Money.'];
        $result = (new AnswerVerifier)->verify('Les autres moyens de paiement documentés incluent Tigo Pesa [SOURCE:1].', collect([$chunk]));
        $this->assertContains('undocumented_payment_method', $result->removedReasons());
    }
}
