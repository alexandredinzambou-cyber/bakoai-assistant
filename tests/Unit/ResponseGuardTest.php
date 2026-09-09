<?php

namespace Tests\Unit;

use App\Services\Assistant\ResponseGuard;
use PHPUnit\Framework\TestCase;

class ResponseGuardTest extends TestCase
{
    public function test_it_detects_fenced_code_blocks(): void
    {
        $guard = new ResponseGuard;

        $this->assertTrue($guard->containsSourceCode("Voici un exemple:\n```php\necho 'ok';\n```"));
    }

    public function test_it_replaces_source_code_with_a_safe_refusal(): void
    {
        $guard = new ResponseGuard;

        $result = $guard->enforce("<?php echo 'secret';");

        $this->assertStringContainsString('Je ne peux pas générer', $result);
        $this->assertFalse($guard->containsSourceCode($result));
    }

    public function test_it_detects_json_like_examples(): void
    {
        $guard = new ResponseGuard;

        $this->assertTrue($guard->containsSourceCode("{\n  \"status_code\": 401\n}"));
    }

    public function test_it_allows_explanatory_api_language(): void
    {
        $guard = new ResponseGuard;

        $text = 'La documentation indique que le callback confirme le statut du paiement et que la source doit etre citee.';

        $this->assertFalse($guard->containsSourceCode($text));
        $this->assertSame($text, $guard->enforce($text));
    }

    public function test_it_allows_backticked_api_identifiers_inside_documentary_prose(): void
    {
        $guard = new ResponseGuard;
        $examples = [
            "Toutes les requêtes doivent utiliser l'en-tête HTTP `X-Secret`.",
            'Utilisez votre `merchant_reference_id` pour identifier la transaction.',
            'La réponse contient un écho du `transactionId` et du `code` reçus.',
            "## Présentation Générale\n\nBienvenue dans la documentation technique officielle de PVit.",
        ];

        foreach ($examples as $example) {
            $this->assertFalse($guard->containsSourceCode($example), $example);
            $this->assertSame($example, $guard->enforce($example));
        }
    }

    public function test_it_allows_short_documentary_examples_in_backticks(): void
    {
        $guard = new ResponseGuard;
        $examples = [
            'Renvoie un accusé de réception dynamique (ex. : `HTTP 200` avec `{ "status": "received" }`).',
            'La réponse attendue est `HTTP 200`.',
            'Exemple de payload minimal : `{"status": "received"}`.',
            'Exemple de liste : `["SUCCESS", "FAILED"]`.',
            'Le code de compte de test (`ACC_*`) sert uniquement en Sandbox.',
            'Les clés de test commencent par `sk_test_*`.',
        ];

        foreach ($examples as $example) {
            $this->assertFalse($guard->containsSourceCode($example), $example);
            $this->assertSame($example, $guard->enforce($example));
        }
    }

    public function test_it_allows_a_markdown_horizontal_rule_before_a_heading(): void
    {
        $guard = new ResponseGuard;
        $examples = [
            "---\n### Résumé des actions immédiates",
            "Texte precedent.\n\n---\n\n## Section suivante\n\nTexte suivant.",
        ];

        foreach ($examples as $example) {
            $this->assertFalse($guard->containsSourceCode($example), $example);
            $this->assertSame($example, $guard->enforce($example));
        }
    }

    public function test_it_still_detects_code_inside_backticks_that_is_not_valid_json_or_http_status(): void
    {
        $guard = new ResponseGuard;
        $examples = [
            'Utilisez `client.pay()` pour continuer.',
            'La commande est `rm -rf tmp` dans cet exemple.',
            'Ajoutez `Content-Type: application/json`.',
            'Le statut retourné est `HTTP 200 OK` (texte libre, pas juste le code).',
        ];

        foreach ($examples as $example) {
            $this->assertTrue($guard->containsSourceCode($example), $example);
        }
    }

    public function test_it_conservatively_detects_common_code_formats(): void
    {
        $guard = new ResponseGuard;
        $examples = [
            'inline code' => 'Utilisez `client.pay()` pour continuer.',
            'inline return' => 'Utilisez `return true` pour continuer.',
            'inline import' => 'Le module utilise `import os` dans cet exemple.',
            'inline shell' => 'La commande est `rm -rf tmp` dans cet exemple.',
            'inline header declaration' => 'Ajoutez `Content-Type: application/json`.',
            'inline indexed access' => 'Lisez ensuite `items[0]`.',
            'one-line JSON' => '{"amount": 1000, "currency": "XAF"}',
            'Python import' => "import requests\nrequests.post('/pay')",
            'SQL' => 'SELECT id, status FROM payments;',
            'SQL scalar' => 'Le résultat est SELECT 1 dans cet exemple.',
            'Python lambda' => 'Utilisez lambda payment: payment.status.',
            'Python list comprehension' => 'La valeur est [payment.status for payment in payments].',
            'Java constructor' => 'Créez new Payment() pour continuer.',
            'shell' => 'curl -X POST https://example.test/pay',
            'HTML' => '<form method="post"></form>',
            'XML' => '<payment><amount>1000</amount></payment>',
            'diff' => "diff --git a/file.php b/file.php\n+return true;",
            'assignment' => 'payment_status = approved',
            'pseudo-code' => "token <- renew_secret\nif token then return token",
            'PHP echo' => 'echo $paymentStatus, $callbackStatus, $completionMessage;',
            'CSS' => ".payment-status {\n  color: green;\n}",
            'Ruby' => 'puts payment_status',
            'Ruby block' => 'payments.each { |payment| process payment }',
            'Rust macro' => 'println!("paid");',
            'Prolog' => 'paid(transaction).',
            'Lisp' => '(map process payments)',
            'Assembly' => 'MOV AX, BX',
            'COBOL' => 'MOVE PAYMENT-AMOUNT TO SETTLEMENT-AMOUNT.',
            'COBOL condition' => 'IF PAYMENT-VALID MOVE PAYMENT-AMOUNT TO SETTLEMENT-AMOUNT.',
            'Erlang' => 'process_payment(Payment, Callback, Merchant) -> approved.',
            'Smalltalk' => "Transcript show: 'Payment approved'.",
            'Smalltalk variable receiver' => 'payment processWith: callback for: merchant account.',
            'Smalltalk unary constructor' => 'PaymentProcessor new process.',
            'Smalltalk unary message chain' => 'Visa process the payment.',
            'Smalltalk constructor keyword message' => 'OrderedCollection new add: payment.',
            'Smalltalk cascade' => 'OrderedCollection new add: payment; yourself.',
            'Smalltalk unary keyword chain' => 'PaymentProcessor current process: payment.',
            'Smalltalk default keyword chain' => 'PaymentProcessor default handle: payment.',
            'Pascal block' => 'begin ProcessPayment(Payment, Callback, Merchant); end.',
            'Raku statement' => 'say "Payment approved";',
            'unknown shell command' => 'cp payment callback merchant .',
            'Ruby postfix call' => 'process payment, callback, merchant if active?',
            'hidden server markup' => 'Texte fiable. <% SELECT 1; %>',
            'shebang' => '#!/usr/bin/env python',
            'preprocessor directive' => '#include <stdio.h>',
            'Windows del command' => 'Del payment to merchant.',
            'Windows type command' => 'Type secret to output.',
            'Windows dir command' => 'Dir payment to output.',
            'Windows shutdown command' => 'Shutdown now for system.',
            'Dockerfile' => 'RUN composer install',
            'YAML' => "amount: 1000\nstatus: approved",
        ];

        foreach ($examples as $label => $example) {
            $this->assertTrue($guard->containsSourceCode($example), "Failed to detect {$label}.");
        }
    }

    public function test_required_citation_suffixes_cannot_hide_source_code(): void
    {
        $guard = new ResponseGuard;

        $this->assertTrue($guard->containsSourceCode("fetch('/pay'); [SOURCE:17]"));
        $this->assertTrue($guard->containsSourceCode('return $token; [SOURCE:17]'));
        $this->assertTrue($guard->containsSourceCode('GET /pay HTTP/1.1 [SOURCE:17]'));
        $this->assertTrue($guard->containsSourceCode('echo $paymentStatus, $callbackStatus, $completionMessage; [SOURCE:17]'));
        $this->assertTrue($guard->containsSourceCode('MOVE PAYMENT-AMOUNT TO SETTLEMENT-AMOUNT. [SOURCE:17]'));
        $this->assertTrue($guard->containsSourceCode('process_payment(Payment) -> approved. [SOURCE:17]'));
        $this->assertTrue($guard->containsSourceCode("e\u{200B}cho \$paymentStatus; [SOURCE:17]"));
    }

    public function test_it_detects_explicit_requests_to_write_or_modify_code(): void
    {
        $guard = new ResponseGuard;
        $requests = [
            'Génère-moi un exemple de code PHP pour initier un paiement.',
            'Corrige ce script JavaScript.',
            'Complète cette fonction Python.',
            'Traduis ce code Java en TypeScript.',
            'Implement a callback handler in Laravel.',
            'Ecris le flux de paiement en COBOL.',
            'Generate the callback in Erlang.',
            'Montre une version Smalltalk.',
            'Fais-moi un exemple PHP pour le paiement PVIT.',
            'Montre comment faire en YAML pour PVIT.',
            'Quelle requete SQL faut-il executer pour PVIT ?',
            'Je veux une lambda Python pour PVIT.',
            "Fix this snippet:\nconst token = 'x';",
        ];

        foreach ($requests as $request) {
            $this->assertTrue($guard->requestsSourceCode($request), "Failed to reject request: {$request}");
        }
    }

    public function test_it_allows_questions_about_error_codes_and_explicitly_code_free_explanations(): void
    {
        $guard = new ResponseGuard;

        $this->assertFalse($guard->requestsSourceCode('Que signifie le code erreur 401 dans la documentation ?'));
        $this->assertFalse($guard->requestsSourceCode('Fournis le code erreur correspondant au secret expiré.'));
        $this->assertFalse($guard->requestsSourceCode('Explique le callback sans générer de code.'));
        $this->assertFalse($guard->requestsSourceCode('Explique le callback et ne génère pas de code.'));
        $this->assertFalse($guard->requestsSourceCode('Explain the authentication flow; do not write code.'));
    }

    public function test_it_allows_questions_about_what_documentation_says_for_a_callback(): void
    {
        $guard = new ResponseGuard;

        $this->assertFalse($guard->requestsSourceCode(
            'Quelles informations la documentation officielle donne-t-elle sur le callback Airtel Money ?',
        ));
        $this->assertFalse($guard->requestsSourceCode('pvit'));
    }

    public function test_it_allows_fenced_curl_json_and_http_examples(): void
    {
        $guard = new ResponseGuard;
        $examples = [
            "Exemple :\n```json\n{\"transactionId\": \"PAY123\", \"status\": \"SUCCESS\"}\n```",
            "Exemple :\n```curl\ncurl -H \"X-Secret: {{secret_key}}\" -d \"amount=150\" https://api.mypvit.pro/v2/pay\n```",
            "Exemple :\n```http\nPOST /v2/{{code_url_payment}}/rest HTTP/1.1\nContent-Type: application/json\nX-Secret: {{secret_key}}\n```",
            "Exemple :\n```\n{\"amount\": 150, \"currency\": \"XAF\"}\n```",
        ];

        foreach ($examples as $example) {
            $this->assertFalse($guard->containsSourceCode($example), $example);
            $this->assertSame($example, $guard->enforce($example));
        }
    }

    public function test_it_allows_json_examples_annotated_with_line_comments(): void
    {
        $guard = new ResponseGuard;
        $example = "Exemple :\n```json\n{\n  \"amount\": 150,\n  \"customer_account_number\": \"074111111\",  // Numero de telephone du client\n  \"owner_charge\": \"CUSTOMER\"  // Le client supporte les frais\n}\n```";

        $this->assertFalse($guard->containsSourceCode($example), $example);
        $this->assertSame($example, $guard->enforce($example));
    }

    public function test_it_does_not_let_a_comment_marker_inside_a_string_value_smuggle_code(): void
    {
        $guard = new ResponseGuard;
        // The "//" here is part of a URL string value, not a comment -- stripping must not
        // touch it, and the JSON must still be judged on its own (valid) merits.
        $example = "```json\n{\"callback_url\": \"https://example.test/pay\"}\n```";

        $this->assertFalse($guard->containsSourceCode($example), $example);
    }

    public function test_it_allows_a_headers_only_http_example(): void
    {
        $guard = new ResponseGuard;
        $example = "En-tetes requis :\n```http\nX-Secret: {{secret_key}}\nX-Callback-MediaType: application/json\nContent-Type: application/json\n```";

        $this->assertFalse($guard->containsSourceCode($example), $example);
        $this->assertSame($example, $guard->enforce($example));
    }

    public function test_it_allows_moustache_placeholders_in_backticks(): void
    {
        $guard = new ResponseGuard;
        $examples = [
            'Les champs `{{secret_key}}` et `{{callback_url_code}}` doivent etre remplaces par vos identifiants reels.',
            'Le champ `{{callback_url_code}}` doit etre remplace par le code genere.',
        ];

        foreach ($examples as $example) {
            $this->assertFalse($guard->containsSourceCode($example), $example);
            $this->assertSame($example, $guard->enforce($example));
        }
    }

    public function test_it_still_blocks_fenced_blocks_that_are_not_valid_api_examples(): void
    {
        $guard = new ResponseGuard;
        $examples = [
            "Voici un exemple:\n```php\necho 'ok';\n```",
            "Exemple invalide :\n```json\n{ not valid json }\n```",
            "Exemple suspect :\n```curl\ncurl https://api.mypvit.pro/v2/pay && rm -rf /\n```",
            "Exemple suspect :\n```bash\ncurl https://api.mypvit.pro/v2/pay\ncomposer require malicious/package\n```",
        ];

        foreach ($examples as $example) {
            $this->assertTrue($guard->containsSourceCode($example), $example);
        }
    }

    public function test_it_preserves_allowed_fenced_examples_while_stripping_other_code_lines(): void
    {
        $guard = new ResponseGuard;
        $text = "Voici comment initier un paiement.\n\n```json\n{\"amount\": 150, \"reference\": \"REF123\"}\n```\n\nfunction hack() { return true; }";

        $stripped = $guard->stripCodeLikeSegments($text);

        $this->assertStringContainsString('```json', $stripped);
        $this->assertStringContainsString('"amount": 150', $stripped);
        $this->assertStringNotContainsString('function hack()', $stripped);
    }

    public function test_it_allows_requests_for_curl_json_and_postman_examples(): void
    {
        $guard = new ResponseGuard;
        $requests = [
            'Montre-moi un exemple de requête curl pour initier un paiement.',
            'Donne-moi un exemple de JSON pour la réponse du callback.',
            'A quoi ressemble une requete postman pour verifier le statut ?',
            'Quel est le format JSON attendu pour le payload de remboursement ?',
        ];

        foreach ($requests as $request) {
            $this->assertFalse($guard->requestsSourceCode($request), $request);
        }
    }

    public function test_it_still_blocks_protocol_examples_mixed_with_an_application_artifact(): void
    {
        $guard = new ResponseGuard;

        $this->assertTrue($guard->requestsSourceCode(
            'Ecris une classe PHP qui envoie une requete curl pour initier un paiement.',
        ));
    }

    public function test_it_allows_reviewing_code_without_fixing_it(): void
    {
        $guard = new ResponseGuard;
        $requests = [
            'Analyse ce code et dis-moi ce qui ne va pas, sans le corriger.',
            "Examine ce script et explique les problemes :\nforeach (\$items as \$i) { echo \$i; }",
            'Peux-tu verifier ce code et lister ses defauts ?',
        ];

        foreach ($requests as $request) {
            $this->assertFalse($guard->requestsSourceCode($request), $request);
        }
    }

    public function test_it_still_blocks_a_review_request_that_also_asks_for_a_fix(): void
    {
        $guard = new ResponseGuard;

        $this->assertTrue($guard->requestsSourceCode('Analyse ce code et corrige-le.'));
        $this->assertTrue($guard->requestsSourceCode('Examine ce script et complete-le.'));
    }

    public function test_it_detects_credentials_and_potential_card_numbers(): void
    {
        $guard = new ResponseGuard;

        $this->assertTrue($guard->containsSensitiveData('Authorization: Bearer secret-token-value-123'));
        $this->assertTrue($guard->containsSensitiveData('X-Secret: merchant-secret-value-123'));
        $this->assertTrue($guard->containsSensitiveData('password=merchant-password-value'));
        $this->assertTrue($guard->containsSensitiveData('Ma carte est 4111 1111 1111 1111'));
        $this->assertTrue($guard->containsSensitiveData('Mon email est dev.partner@example.com'));
        $this->assertTrue($guard->containsSensitiveData('Téléphone: +241 06 12 34 56'));
        $this->assertTrue($guard->containsSensitiveData('merchant_id=merchant-12345'));
        $this->assertFalse($guard->containsSensitiveData('Ecrivez a notre support technique : support@mypvit.pro'));
        $this->assertFalse($guard->containsSensitiveData('Contactez-nous : contact@mypvit.pro'));
        $this->assertTrue($guard->containsSensitiveData('Mon IP est 203.0.113.42'));
        $this->assertFalse($guard->containsSensitiveData('Comment fonctionne le header X-Secret ?'));
        $this->assertFalse($guard->containsSensitiveData('Que signifie le champ merchant_id ?'));
    }

    public function test_it_allows_pvit_style_moustache_placeholders_but_not_other_placeholder_styles(): void
    {
        $guard = new ResponseGuard;

        $this->assertFalse($guard->containsSensitiveData("X-Secret: {{secret_key}}"));
        $this->assertFalse($guard->containsSensitiveData("merchant_operation_account_code: {{operation_account_code}}"));
        $this->assertFalse($guard->containsSensitiveData("Le champ callback_url_code vaut {{callback_url_code}}."));

        $this->assertTrue($guard->containsSensitiveData('X-Secret: YOUR_SECRET_KEY_HERE'));
        $this->assertTrue($guard->containsSensitiveData('X-Secret: XXXXXXXXXXXXXXXX'));
        $this->assertTrue($guard->containsSensitiveData('X-Secret: sk_live_87e1f459a2b3c4d5'));
    }
}
