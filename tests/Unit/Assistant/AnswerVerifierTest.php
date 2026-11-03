<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerVerifier;
use App\Services\Assistant\VerifiedAnswer;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AnswerVerifier on extracts shaped like the real knowledge base: documented content is kept
 * whether or not it is cited; invented specifics, contradictions, guesses and fabricated
 * examples are removed.
 */
class AnswerVerifierTest extends TestCase
{
    private function chunks(): Collection
    {
        return new Collection([
            (object) ['chunk_id' => 646, 'document_titre' => 'Webhook de notification de statut', 'section' => 'Webhook de notification de statut', 'contenu' => <<<'MD'
Une fois la transaction traitée par l'opérateur mobile, PVit envoie **immédiatement une notification (Webhook)** à l'URL de callback que vous avez renseignée lors de la requête initiale. Cette notification contient le statut final et définitif de la transaction (**SUCCESS** ou **FAILED**) ainsi que tous les détails de l'opération.

- Une **URL publiquement joignable**, enregistrée dans votre espace marchand puis désignée par callback_url_code dans vos requêtes.
- Elle doit accepter une requête **POST** émise par PVit.
- Le format du corps suit l'en-tête X-Callback-MediaType : application/json (par défaut) ou application/xml. Il ne s'applique qu'aux transactions PAYMENT.
MD],
            (object) ['chunk_id' => 647, 'document_titre' => 'Webhook de notification de statut', 'section' => 'Accusé de réception attendu de votre serveur', 'contenu' => <<<'MD'
## Accusé de réception attendu de votre serveur

Pour confirmer que votre système a bien reçu la notification, votre serveur doit impérativement répondre à notre requête POST avec un code HTTP 200 OK et renvoyer un objet JSON contenant un écho du transactionId et du code reçus.

```json
{
  "transactionId": "PAY23102400001",
  "responseCode": 200
}
```
MD],
            (object) ['chunk_id' => 607, 'document_titre' => "Guide d'Intégration – Sandbox", 'section' => 'Webhook de Notification (Callback)', 'contenu' => <<<'MD'
### Webhook de Notification (Callback)

- Allez dans Urls et cliquez sur le bouton «**+Url**».
- Sélectionnez le type «**Callback**» renseignez une URL publique capable de recevoir des requêtes POST.

Les urls en **localhost** ou **127.0.0.1** ne seront pas **valides**.

Si aucune notification n'est reçue après un délai raisonnable (ex. 3 minutes), interrogez l'API Check Status.
MD],
            (object) ['chunk_id' => 581, 'document_titre' => 'Initier un paiement', 'section' => 'Initier un paiement', 'contenu' => <<<'MD'
Encaissez un paiement mobile ou carte en une seule requête serveur-à-serveur.
Base URL : https://api.mypvit.pro/v2
Endpoint : POST /{code-url-payment}/rest
## En-têtes
- X-Secret (string, Obligatoire) — Clé secrète authentifiant l'opération de compte.
- X-Callback-MediaType (string) — Type de média optionnel pour la réponse de callback.
## Corps de la requête
- agent (string) — Agent associé à la transaction (optionnel).
- amount (number · double, Obligatoire) — Le montant de la transaction (valeur positive).
- reference (string, Obligatoire) — Référence alphanumérique unique de la transaction (max 20 car.).
- callback_url_code (string) — Code de l'URL de callback pour les notifications webhook.
- customer_account_number (string, Obligatoire) — Numéro de compte du client (téléphone / MSISDN).
- operator_code (string, Obligatoire) — Code de l'opérateur qui traite la transaction.
La transaction est créée avec le statut PENDING. Le statut final (SUCCESS ou FAILED) vous sera notifié de façon asynchrone via un webhook.
MD],
            (object) ['chunk_id' => 583, 'document_titre' => 'Initier un paiement', 'section' => 'Exemple de requête (cURL)', 'contenu' => <<<'MD'
## Exemple de requête (cURL)
```bash
curl -X POST "https://api.mypvit.pro/v2/{{code_url_payment}}/rest" \
 -H "X-Secret: {{secret_key}}" \
 -H "Content-Type: application/json" \
 -d '{
 "amount": 150,
 "reference": "REF1234567890",
 "customer_account_number": "074111111",
 "operator_code": "AIRTEL_MONEY"
}'
```
MD],
            (object) ['chunk_id' => 600, 'document_titre' => "Statut d'une transaction", 'section' => "Statut d'une transaction", 'contenu' => <<<'MD'
Base URL : https://api.mypvit.pro/v2
Endpoint : GET /{code-url-status}/status
- transactionId (string, Obligatoire) — Identifiant de la transaction PVit.
MD],
            (object) ['chunk_id' => 620, 'document_titre' => 'Initier un remboursement', 'section' => 'Réponse', 'contenu' => <<<'MD'
Le remboursement est traité de façon synchrone : la réponse renvoie directement le statut final (SUCCESS ou FAILED). Aucun webhook n'est nécessaire.
MD],
            (object) ['chunk_id' => 645, 'document_titre' => 'Webhook de reporting', 'section' => 'Ce que votre endpoint doit répondre', 'contenu' => <<<'MD'
Répondez **2xx** dès que la notification est enregistrée.

- Jusqu'à **5 tentatives**, espacées de façon croissante : 5 minutes, 15 minutes, 1 heure, puis 6 heures.
MD],
            (object) ['chunk_id' => 660, 'document_titre' => "Codes d'erreur communs", 'section' => 'Codes', 'contenu' => <<<'MD'
| Code | Constante | Description | HTTP |
| 1000 | INVALID_MERCHANT_IP_ADDRESS | The ip address is invalid for this merchant | 400 |
| 1002 | INVALID_CALLBACK_URL_CODE | This callback URL is invalid | 400 |
| 1006 | INVALID_SECRET_KEY | The secret key is invalid | 403 |
MD],
            (object) ['chunk_id' => 670, 'document_titre' => 'Ajouter une adresse IP', 'section' => 'Étapes', 'contenu' => <<<'MD'
Dans l'espace marchand, cliquez sur « Paramétrages » puis sur « Adresses IPs ». Les adresses de PVit sont 176.31.65.18 et 176.31.65.20. La clé secrète expire après 3600 secondes.
MD],
        ]);
    }

    private function verify(string $answer, string $question = ''): VerifiedAnswer
    {
        return (new AnswerVerifier)->verify($answer, $this->chunks(), $question);
    }

    // ---- Payment methods -------------------------------------------------------------------

    public function test_undocumented_payment_methods_are_cut_out_of_an_enumeration(): void
    {
        $verified = $this->verify(
            "PVit encaisse un paiement mobile ou carte en une seule requête serveur-à-serveur, via :\n\n"
            ."- **Mobile Money** (Airtel Money, Moov Money, Orange Money, MTN Mobile Money)\n"
            .'- **Cartes bancaires** (Visa, Mastercard)'
        );

        $this->assertStringContainsString('Airtel Money, Moov Money', $verified->answer);
        $this->assertStringContainsString('Visa, Mastercard', $verified->answer);
        $this->assertStringNotContainsString('Orange Money', $verified->answer);
        $this->assertStringNotContainsString('MTN', $verified->answer);
        $this->assertContains('undocumented_payment_method', array_column($verified->removed, 'reason'));
    }

    public function test_a_statement_about_an_undocumented_payment_method_is_removed_whole(): void
    {
        $verified = $this->verify(
            "PVit encaisse un paiement mobile ou carte en une seule requête serveur-à-serveur.\n\n"
            .'PVit accepte aussi les paiements Orange Money pour les marchands du Cameroun.'
        );

        $this->assertStringNotContainsString('Orange Money', $verified->answer);
        $this->assertStringContainsString('une seule requête serveur-à-serveur', $verified->answer);
    }

    public function test_a_payment_method_named_in_the_question_is_not_treated_as_invented(): void
    {
        $verified = $this->verify(
            'La documentation ne mentionne pas Orange Money parmi les moyens de paiement.',
            'PVit accepte-t-il Orange Money ?',
        );

        $this->assertNotContains('undocumented_payment_method', array_column($verified->removed, 'reason'));
    }
    // ---- What is kept ----------------------------------------------------------------------

    public function test_documented_content_is_kept_without_any_citation(): void
    {
        $answer = "PVit envoie une notification à votre URL de callback avec le statut final SUCCESS ou FAILED.\n\n"
            ."Pour la configurer :\n\n"
            ."1. Allez dans Urls et cliquez sur le bouton « +Url ».\n"
            ."2. Sélectionnez le type « Callback » et renseignez une URL publique capable de recevoir des requêtes POST.\n\n"
            .'Votre serveur doit répondre avec un code HTTP 200 OK et un écho du transactionId et du code reçus.';

        $verified = $this->verify($answer);

        $this->assertTrue($verified->acceptable);
        $this->assertSame([], $verified->removed);
        $this->assertSame($answer, $verified->answer);
        $this->assertSame([], $verified->citedIds);
        $this->assertNotSame([], $verified->supportingIds, 'An uncited answer still gets its supporting extracts.');
    }

    public function test_paraphrases_conversions_question_values_and_documented_prefixes_are_kept(): void
    {
        $verified = $this->verify(
            "La clé secrète est valable 3600 secondes, soit 1 heure.\n\n"
            ."Le statut peut être PENDING juste après l'initiation, puis SUCCESS ou FAILED.\n\n"
            ."Pour un partage 80 % / 20 %, indiquez le montant de 150 XAF dans amount.\n\n"
            .'Le mot de passe de l API n est pas le même que celui du compte, et si aucune notification n est reçue après 3 minutes, interrogez l API Check Status.',
            'je veux partager 80% / 20% entre deux comptes',
        );

        $this->assertSame([], $verified->removed, json_encode($verified->removed));
    }

    public function test_json_annotations_are_removed_from_the_displayed_example(): void
    {
        $verified = $this->verify("```json\n{\n\"operator_code\": \"AIRTEL_MONEY\", // opérateur documenté\n\"amount\": 150\n}\n```");
        preg_match('/```json\s*(.*?)```/su', $verified->answer, $match);
        $decoded = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('AIRTEL_MONEY', $decoded['operator_code']);
        $this->assertSame(150, $decoded['amount']);
        $this->assertStringNotContainsString('// opérateur', $verified->answer);
    }

    public function test_courtesy_lines_gaps_and_documented_links_are_kept(): void
    {
        $verified = $this->verify(
            "Configurez l'URL de callback sur [mypvit.pro](https://mypvit.pro) : menu Urls, bouton +Url, type Callback.\n\n"
            ."La documentation fournie ne précise pas de délai de nouvelle tentative pour ce webhook.\n\n"
            ."N'hésitez pas si vous avez d'autres questions.",
        );

        $this->assertTrue($verified->acceptable);
        $this->assertSame([], $verified->removed);
        $this->assertStringContainsString('sur mypvit.pro :', $verified->answer);
        $this->assertStringNotContainsString('](https://', $verified->answer);
    }

    public function test_a_documented_example_is_kept_verbatim_or_adapted_with_placeholders(): void
    {
        $verbatim = "Exemple de la documentation :\n\n```json\n{\n  \"transactionId\": \"PAY23102400001\",\n  \"responseCode\": 200\n}\n```";
        $adapted = "Exemple :\n\n```bash\ncurl -X POST \"https://api.mypvit.pro/v2/{{code_url_payment}}/rest\" \\\n"
            ." -H \"X-Secret: {{votre_cle_secrete}}\" \\\n -H \"Content-Type: application/json\" \\\n"
            ." -d '{\n \"amount\": 150,\n \"reference\": \"{{votre_reference}}\",\n \"customer_account_number\": \"241060000000\",\n \"operator_code\": \"AIRTEL_MONEY\"\n}'\n```";
        $status = "```bash\ncurl -X GET \"https://api.mypvit.pro/v2/{{code_url_status}}/status?transactionId={{transaction_id}}\" -H \"X-Secret: {{secret_key}}\"\n```";

        foreach ([$verbatim, $adapted, $status] as $answer) {
            $verified = $this->verify($answer);
            $this->assertSame([], $verified->removed, json_encode($verified->removed));
        }
    }

    // ---- What is removed -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function errors(): array
    {
        return [
            'invented algorithm' => ['Calculez la signature HMAC-SHA256 du corps reçu. [SOURCE:646]', 'fabricated_identifier'],
            'invented header' => ["La signature est transmise dans l'en-tête X-Signature. [SOURCE:646]", 'fabricated_identifier'],
            'invented code value' => ['Utilisez le code de callback de votre compte (ex. `CALLBACK123`).', 'fabricated_identifier'],
            'invented endpoint' => ['Le remboursement passe par l endpoint /refund de l API.', 'fabricated_identifier'],
            'invented retry count' => ['PVit relance la notification 3 fois si votre serveur ne répond pas.', 'fabricated_value'],
            'invented delay' => ['La notification est renvoyée toutes les 10 minutes.', 'fabricated_value'],
            'invented IP address' => ['Autorisez aussi l adresse 176.31.65.19 sur votre pare-feu.', 'fabricated_value'],
            'invented currency' => ['- `amount` : le montant, exprimé en centimes.', 'fabricated_value'],
            'invented link' => ['Consultez https://docs.pvit-help.com/callback pour les détails.', 'fabricated_link'],
            'unknown source' => ['Le secret est toujours public. [SOURCE:999]', 'unknown_source'],
            'optional field called mandatory' => ['Le champ `callback_url_code` est obligatoire pour initier un paiement.', 'contradiction'],
            'mandatory field called optional' => ['Le champ `reference` est facultatif.', 'contradiction'],
            'wrong HTTP method' => ['Envoyez un POST /{code-url-status}/status pour connaître le statut.', 'contradiction'],
            'asynchronous refund' => ['Le remboursement est une opération asynchrone confirmée par un callback.', 'contradiction'],
            'exclusive format' => ['Le callback est envoyé uniquement en JSON.', 'contradiction'],
            'false documentation gap' => ['La documentation ne mentionne pas le format XML pour le callback.', 'contradiction'],
            'constant with the wrong code' => ["L'erreur 1002 INVALID_MERCHANT_IP_ADDRESS signale une IP non déclarée.", 'contradiction'],
            'constant with the wrong HTTP status' => ['INVALID_MERCHANT_IP_ADDRESS est renvoyée avec un HTTP 403.', 'contradiction'],
            'localhost granted' => ['Les URL en localhost sont acceptées en sandbox pour recevoir le callback.', 'contradiction'],
            'acknowledgement waived' => ["Votre serveur n'a pas besoin de répondre avec un code HTTP 200 à la requête POST de PVit.", 'contradiction'],
            'figure of the sibling page' => ["Le webhook de statut est renvoyé jusqu'à 5 fois : 5 minutes, 15 minutes, 1 heure puis 6 heures.", 'misattributed_detail'],
            'hedged guess' => ['Le code se trouve généralement dans le menu Webhooks de votre espace.', 'speculation'],
            'invented UI label' => ['Cliquez sur « Paramètres avancés » puis sur « Adresses IPs ».', 'fabricated_label'],
            'hidden markup' => ['Le secret doit être renouvelé. <% Le remboursement est automatique. %>', 'code_block'],
        ];
    }

    #[DataProvider('errors')]
    public function test_a_real_error_is_removed(string $answer, string $reason): void
    {
        $verified = $this->verify($answer);

        $this->assertCount(1, $verified->removed, json_encode($verified->removed));
        $this->assertSame($reason, $verified->removed[0]['reason'], json_encode($verified->removed));
        $this->assertFalse($verified->acceptable);
        $this->assertSame(0, $verified->keptUnits);
    }

    public function test_a_strong_qualifier_is_checked_against_its_own_subject(): void
    {
        $chunks = $this->chunks()->reject(static fn (object $chunk): bool => $chunk->chunk_id === 620)->values();
        $chunks->push((object) ['chunk_id' => 621, 'document_titre' => 'Initier un remboursement', 'section' => 'Corps', 'contenu' => '- amount (number, Obligatoire) — Montant du remboursement.']);

        // The only "asynchrone" of the extracts is about payments: it cannot vouch for refunds.
        $verified = (new AnswerVerifier)->verify('Le remboursement est une opération asynchrone.', $chunks);
        $this->assertSame('unsupported_qualifier', $verified->removed[0]['reason'] ?? null);

        $kept = (new AnswerVerifier)->verify('Le statut final du paiement est notifié de façon asynchrone via un webhook.', $chunks);
        $this->assertSame([], $kept->removed);
    }

    public function test_a_qualifier_is_supported_across_conjugations_of_its_subject(): void
    {
        // Real refusal of "Que dit la documentation sur les callbacks ?": callback, serveur,
        // information and final are in most extracts, so the rarest word of the claim, the one
        // that names its subject, is "envoyées". The extract says "PVit envoie", and the two
        // stems used to differ (envoy / envoi).
        $chunks = new Collection([
            (object) ['chunk_id' => 607, 'document_titre' => "Guide d'Intégration – Sandbox", 'section' => 'Prérequis & Identifiants', 'contenu' => "Le **Callback** est une requête asynchrone (Webhook) que PVit envoie à votre serveur pour vous informer du statut final d'une transaction."],
            (object) ['chunk_id' => 645, 'document_titre' => 'Configurer une URL de callback', 'section' => 'Callback', 'contenu' => "Votre serveur reçoit sur l'URL de callback l'information du statut final."],
            (object) ['chunk_id' => 646, 'document_titre' => 'Webhook de reporting', 'section' => 'Callback', 'contenu' => 'Le callback informe votre serveur du statut final de la transaction.'],
            (object) ['chunk_id' => 612, 'document_titre' => "Guide d'Intégration – Sandbox", 'section' => 'Accusé de réception', 'contenu' => "Votre serveur répond au callback avec l'information reçue et le statut final."],
            (object) ['chunk_id' => 588, 'document_titre' => 'Génération de la clé secrète', 'section' => 'Génération de la clé secrète', 'contenu' => 'La clé secrète est envoyée dans la réponse JSON et reste valable 3600 secondes.'],
        ]);

        $verified = (new AnswerVerifier)->verify(
            'Les **callbacks** sont des notifications asynchrones envoyées par PVit à votre serveur pour informer du statut final d’une transaction.',
            $chunks,
        );

        $this->assertSame([], $verified->removed);
    }

    public function test_the_bold_title_of_a_list_item_is_not_an_interface_label(): void
    {
        // Real draft: the item's own title sat next to a navigation cue ("menu") and was read as
        // a button of the interface.
        $kept = (new AnswerVerifier)->verify(
            '1. **Mise en place initiale** : renseignez une URL publique de type *Callback* via le menu *Urls*, en évitant les adresses **localhost** ou **127.0.0.1** [SOURCE:607].',
            $this->chunks(),
        );
        $this->assertSame([], $kept->removed);

        // A bold label inside the navigation step is still the interface's, and is still checked.
        $removed = (new AnswerVerifier)->verify(
            '1. **Configuration** : allez dans le menu **Paramètres avancés** et cliquez sur **+Url** [SOURCE:607].',
            $this->chunks(),
        );
        $this->assertSame('fabricated_label', $removed->removed[0]['reason'] ?? null);
    }

    public function test_a_path_quoted_by_the_extracts_is_kept_without_an_endpoint_line(): void
    {
        // Real PrestaShop guide: the example path is in the plugin page, which declares no
        // endpoint, and was removed from every answer as invented.
        $chunks = $this->chunks()
            ->filter(static fn (object $chunk): bool => $chunk->chunk_id === 581)
            ->push((object) ['chunk_id' => 622, 'document_titre' => 'PrestaShop', 'section' => 'PrestaShop', 'contenu' => "- **Status URL** : Allez dans APIs → CHECK STATUS.\n  Exemple: /UILUQWSEOWFCE5G/status"])
            ->values();

        $kept = (new AnswerVerifier)->verify('- **Status URL** : allez dans **APIs → CHECK STATUS** pour obtenir l’URL (ex: `/UILUQWSEOWFCE5G/status`) [SOURCE:622].', $chunks);
        $this->assertSame([], $kept->removed);

        $removed = (new AnswerVerifier)->verify('- **Status URL** : utilisez l’URL `/ABCDEFGH1234/statut` [SOURCE:622].', $chunks);
        $this->assertSame('fabricated_identifier', $removed->removed[0]['reason'] ?? null);
    }

    public function test_a_numbered_step_whose_sub_items_were_all_removed_is_dropped(): void
    {
        $verified = (new AnswerVerifier)->verify(
            "Pour recevoir les notifications :\n\n1. Allez dans Urls et cliquez sur le bouton «+Url» [SOURCE:607].\n2. Vérifiez les URLs de callback et de réception :\n   - Utilisez l'en-tête `X-Prestashop-Token` [SOURCE:607].\n3. Sélectionnez le type «Callback» [SOURCE:607].",
            $this->chunks(),
        );

        $this->assertSame('fabricated_identifier', $verified->removed[0]['reason'] ?? null);
        $this->assertStringNotContainsString('Vérifiez les URLs', $verified->answer);
        $this->assertStringContainsString('2. Sélectionnez le type «Callback»', $verified->answer);

        // A step followed by unindented bullets still introduces them.
        $kept = (new AnswerVerifier)->verify(
            "1. Renseignez votre URL de callback :\n- une URL publique capable de recevoir des requêtes POST [SOURCE:607].",
            $this->chunks(),
        );
        $this->assertStringContainsString('Renseignez votre URL de callback', $kept->answer);
    }

    public function test_a_removed_example_takes_its_announcement_and_lone_citation_with_it(): void
    {
        // Real screenshot diagnosis: the code example was stripped, its announcement and its
        // citation stayed on two lines of their own.
        $rendered = (new AnswerVerifier)->renderNumbered(
            "1. **Vérifiez le code** : il doit correspondre à une URL active [SOURCE:607].\n   *Exemple de champ dans la requête :*\n [SOURCE:646]\n\n2. Contactez le support si besoin.\n\nLe code est attribué par PVit.\n [SOURCE:607] et [SOURCE:646]",
            $this->chunks(),
        );

        $this->assertStringNotContainsString('Exemple de champ', $rendered['answer']);
        $this->assertStringContainsString('Le code est attribué par PVit. [1][2]', $rendered['answer']);
        $this->assertDoesNotMatchRegularExpression('/^\s*\[\d+\]/m', $rendered['answer']);
    }

    public function test_items_under_a_mandatory_lead_in_inherit_its_claim(): void
    {
        $verified = $this->verify(
            "Les champs obligatoires sont :\n\n- `amount` : le montant\n- `reference` : la référence unique\n- `agent` : l agent de la transaction",
        );

        $this->assertCount(1, $verified->removed);
        $this->assertStringContainsString('`agent`', $verified->removed[0]['text']);
        $this->assertSame('contradiction', $verified->removed[0]['reason']);
        $this->assertStringContainsString('`reference`', $verified->answer);
    }

    public function test_a_guess_in_parentheses_is_cut_out_and_the_sentence_kept(): void
    {
        $verified = $this->verify('Allez dans le menu Urls (généralement sous Paramétrages) et cliquez sur +Url.');

        $this->assertTrue($verified->acceptable);
        $this->assertSame('Allez dans le menu Urls et cliquez sur +Url.', $verified->answer);
        $this->assertSame('speculation', $verified->removed[0]['reason']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fabricatedExamples(): array
    {
        return [
            'invented JSON key' => ["```json\n{\n  \"transaction_id\": \"PAY23102400001\",\n  \"provider\": \"AIRTEL_MONEY\"\n}\n```"],
            'invented enum value' => ["```json\n{\n  \"operator_code\": \"MPESA_KENYA\"\n}\n```"],
            'invented reference' => ["```json\n{\n  \"reference\": \"REF20240512345\"\n}\n```"],
            'invented phone number outside a phone field' => ["```json\n{\n  \"reference\": \"+22512345678\"\n}\n```"],
            'placeholder replaced by an invented code' => ["```bash\ncurl -X POST \"https://api.mypvit.pro/v2/airtel-money/rest\" -H \"X-Secret: {{secret_key}}\"\n```"],
            'invented header' => ["```bash\ncurl -X POST \"https://api.mypvit.pro/v2/{{code_url_payment}}/rest\" -H \"Authorization: Bearer {{token}}\"\n```"],
            'wrong method' => ["```bash\ncurl -X POST \"https://api.mypvit.pro/v2/{{code_url_status}}/status\" -H \"X-Secret: {{secret_key}}\"\n```"],
            'invented host' => ["```bash\ncurl -X POST \"https://api.pvit-pay.com/v2/{{code_url_payment}}/rest\"\n```"],
            'application code' => ["```php\n\$client = new PaymentClient(\$secret);\n```"],
        ];
    }

    #[DataProvider('fabricatedExamples')]
    public function test_a_fabricated_example_is_removed_with_its_label(string $block): void
    {
        $verified = $this->verify("Votre serveur doit répondre avec un code HTTP 200 OK. [SOURCE:647]\n\nExemple :\n\n".$block);

        $this->assertCount(1, $verified->removed, json_encode($verified->removed));
        $this->assertContains($verified->removed[0]['reason'], ['invalid_example', 'code_block']);
        $this->assertSame('Votre serveur doit répondre avec un code HTTP 200 OK. [SOURCE:647]', $verified->answer);
        $this->assertTrue($verified->acceptable);
    }

    public function test_a_long_passage_of_general_knowledge_is_removed(): void
    {
        $verified = $this->verify(
            "PVit envoie une notification à votre URL de callback avec le statut final SUCCESS ou FAILED.\n\n"
            .'Mettez en place une file de messages persistante avec redistribution exponentielle, surveillance centralisée, '
            .'alertes proactives et tableaux de bord pour superviser durablement la résilience applicative globale.',
        );

        $this->assertSame('ungrounded', $verified->removed[0]['reason'] ?? null);
        $this->assertTrue($verified->acceptable);
    }

    public function test_a_passage_the_documentation_disavows_is_not_restated_as_a_fact(): void
    {
        $chunks = new Collection([(object) ['chunk_id' => 17, 'document_titre' => 'Authentification', 'section' => 'Exemples', 'contenu' => "Exemple incorrect\nLe secret est partagé publiquement entre tous les marchands.\n\nLe secret est propre à chaque compte d'opération."]]);

        $verified = (new AnswerVerifier)->verify('Le secret est partagé publiquement entre tous les marchands. [SOURCE:17]', $chunks);

        $this->assertSame('contradiction', $verified->removed[0]['reason'] ?? null);
    }

    public function test_citations_quoting_a_title_are_read_as_citations(): void
    {
        $verified = $this->verify('PVit envoie une notification avec le statut final SUCCESS ou FAILED ["Webhook de notification de statut" **SOURCE:646**].');

        $this->assertSame([], $verified->removed);
        $this->assertSame([646], $verified->citedIds);
        $this->assertStringEndsWith('FAILED [SOURCE:646].', $verified->answer);
    }

    public function test_a_wrong_parenthesis_on_a_required_step_is_cut_out(): void
    {
        $verified = $this->verify('Votre serveur doit répondre (optionnel mais recommandé) à la requête POST avec un code HTTP 200 OK et un écho du transactionId.');

        $this->assertTrue($verified->acceptable);
        $this->assertSame('contradiction', $verified->removed[0]['reason']);
        $this->assertSame('Votre serveur doit répondre à la requête POST avec un code HTTP 200 OK et un écho du transactionId.', $verified->answer);
    }

    public function test_a_gap_about_one_format_is_no_false_gap_on_the_format_it_documents(): void
    {
        $chunks = $this->chunks()->map(static fn (object $chunk): object => (object) array_merge((array) $chunk, [
            'contenu' => str_replace(['application/json (par défaut) ou application/xml', 'application/json'], ['application/json', 'application/json'], $chunk->contenu),
        ]));

        $verified = (new AnswerVerifier)->verify(
            'La documentation ne précise pas que le format XML est supporté pour le callback : elle ne mentionne que le format JSON.',
            $chunks,
        );

        $this->assertSame([], $verified->removed, json_encode($verified->removed));
    }

    public function test_example_domains_conditions_and_per_field_modalities_are_kept(): void
    {
        $verified = $this->verify(
            'Renseignez votre URL publique (ex. : https://votre-domaine.com/api/callback).

'
            ."Ajoutez l'en-tête obligatoire `X-Secret` et l'en-tête optionnel `X-Callback-MediaType`.

"
            .'Renseignez `customer_account_number` si requis par le service.',
        );

        $this->assertSame([], $verified->removed, json_encode($verified->removed));
    }

    public function test_json_annotations_are_ignored_even_when_they_quote_alternatives(): void
    {
        $verified = $this->verify('```json
{
  "operator_code": "AIRTEL_MONEY", // ou "MOOV_MONEY" selon l operateur
  "amount": 150
}
```');

        $this->assertSame([], $verified->removed, json_encode($verified->removed));
    }

    // ---- Structure and acceptability ---------------------------------------------------------

    public function test_one_wrong_item_is_removed_and_the_list_kept_in_place(): void
    {
        $verified = $this->verify(
            "Votre serveur doit :\n\n"
            ."- recevoir la requête POST de PVit [SOURCE:646]\n"
            ."- répondre avec un code HTTP 200 OK et un écho du transactionId et du code [SOURCE:647]\n"
            .'- vérifier la signature HMAC de la requête avant de la traiter [SOURCE:646]',
        );

        $this->assertTrue($verified->acceptable);
        $this->assertSame("Votre serveur doit :\n\n- recevoir la requête POST de PVit [SOURCE:646]\n- répondre avec un code HTTP 200 OK et un écho du transactionId et du code [SOURCE:647]", $verified->answer);
    }

    public function test_emptied_labels_and_lead_ins_disappear_and_steps_are_renumbered(): void
    {
        $verified = $this->verify(
            "1. **Recevoir la notification** :\n"
            ."   - PVit envoie une requête POST à votre URL de callback avec le statut final SUCCESS ou FAILED\n"
            ."2. **Vérifier la signature** :\n"
            ."   - la signature HMAC-SHA256 est transmise dans X-Signature\n"
            ."3. **Répondre à PVit** :\n"
            ."   - votre serveur répond avec un code HTTP 200 OK et un écho du transactionId et du code reçus\n\n"
            ."Voici l'algorithme à utiliser :\n\n"
            .'- HMAC-SHA512 sur le corps brut',
        );

        $this->assertStringNotContainsString('Vérifier la signature', $verified->answer);
        $this->assertStringNotContainsString("Voici l'algorithme", $verified->answer);
        $this->assertStringContainsString('2. **Répondre à PVit** :', $verified->answer);
        $this->assertStringNotContainsString('3.', $verified->answer);
    }

    public function test_acceptability_depends_on_the_opening_claim_and_on_what_is_left(): void
    {
        $openingWrong = $this->verify(
            "La notification est signée en HMAC-SHA256.\n\n"
            ."Votre serveur doit répondre avec un code HTTP 200 OK.\n\n"
            .'PVit envoie la notification avec le statut final SUCCESS ou FAILED.',
        );
        $this->assertFalse($openingWrong->acceptable);
        $this->assertSame('opening_removed', $openingWrong->reason);
        $this->assertSame(2, $openingWrong->keptUnits);

        $mostlyWrong = $this->verify(
            "PVit envoie la notification avec le statut final SUCCESS ou FAILED.\n\n"
            ."- signature HMAC-SHA256\n- en-tête X-Signature\n- 3 tentatives",
        );
        $this->assertSame('too_much_removed', $mostlyWrong->reason);

        $this->assertSame('nothing_left', $this->verify('Utilisez HMAC-SHA256.')->reason);
        $this->assertTrue($this->verify('PVit envoie la notification avec le statut final SUCCESS ou FAILED.')->isBetterThan($mostlyWrong));
    }

    // ---- Rendering -------------------------------------------------------------------------

    public function test_numbered_rendering_follows_first_use_and_returns_the_cited_sources(): void
    {
        $verifier = new AnswerVerifier;
        $rendered = $verifier->renderNumbered(
            "PVit envoie une notification. **[SOURCE:646]**\n\nVotre serveur répond HTTP 200. [SOURCE:647][SOURCE:647]\n\nLe statut est final. [SOURCE:646, SOURCE:999]",
            $this->chunks(),
        );

        $this->assertSame("PVit envoie une notification. [1]\n\nVotre serveur répond HTTP 200. [2]\n\nLe statut est final. [1]", $rendered['answer']);
        $this->assertSame([646, 647], array_map(static fn (object $chunk): int => $chunk->chunk_id, $rendered['sources']));

        $orphan = $verifier->renderNumbered("- Effectuez deux simulations d echec.\n [SOURCE:646] [SOURCE:647]", $this->chunks());
        $this->assertSame('- Effectuez deux simulations d echec. [1][2]', $orphan['answer']);

        $uncited = $verifier->renderNumbered('Votre serveur doit répondre à la requête POST avec un code HTTP 200 OK et un écho du transactionId.', $this->chunks());
        $this->assertSame(647, $uncited['sources'][0]->chunk_id ?? null, 'An uncited answer is sourced by the extracts it matches.');
    }

    public function test_label_rendering_sanitizes_the_document_metadata(): void
    {
        $chunks = new Collection([(object) ['chunk_id' => 17, 'document_titre' => '<script>alert(1)</script>Guide [lien](https://evil.test)', 'section' => 'Etape `1` | https://evil.test', 'contenu' => 'Le secret doit etre renouvele avant expiration.']]);

        $rendered = (new AnswerVerifier)->renderLabels('Le secret doit etre renouvele avant expiration. [SOURCE:17] [SOURCE:99]', $chunks);

        $this->assertSame('Le secret doit etre renouvele avant expiration. [Guide lien / Etape 1]', $rendered);
    }

    public function test_the_removals_are_described_for_a_correction_prompt(): void
    {
        $verified = $this->verify('Calculez la signature HMAC-SHA256 du corps reçu.');

        $this->assertStringContainsString('"Calculez la signature HMAC-SHA256 du corps reçu." (identifiant absent des extraits : HMAC-SHA256)', AnswerVerifier::describeRemovals($verified->removed));
    }
}
