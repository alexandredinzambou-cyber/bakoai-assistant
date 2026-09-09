<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

class ResponseGuard
{
    private const SAFE_REFUSAL = 'Je ne peux pas générer, corriger, compléter ni traduire du code source. Je peux uniquement expliquer en langage naturel les principes d’intégration présents dans la documentation officielle.';

    private const SENSITIVE_DATA_REFUSAL = 'Je ne peux pas traiter une question contenant un secret, un identifiant marchand, une donnée personnelle ou une donnée de paiement potentielle. Retirez cette donnée sensible puis reformulez votre question.';

    private const SENSITIVE_OUTPUT_REFUSAL = 'La réponse générée a été bloquée car elle pourrait contenir une donnée sensible. Aucune valeur potentiellement confidentielle ne sera affichée ; je recommande de vérifier la source documentaire ou d’ouvrir un ticket de support.';

    private const PUBLIC_CONTACT_EMAILS = [
        'support@mypvit.pro',
        'contact@mypvit.pro',
    ];

    public function containsSensitiveData(string $text): bool
    {
        $text = $this->normalizeForInspection($text);

        // A moustache-style placeholder token (e.g. "{{secret_key}}", "{{operation_account_code}}")
        // is exactly how the PVIT documentation itself denotes a value the developer must
        // substitute -- it is never a leaked credential. Collapse it to a short neutral marker
        // before the length-based checks below so it can never satisfy them. Deliberately
        // narrow to this one PVIT-specific convention: any other placeholder style ("YOUR_KEY_HERE",
        // "XXXXXXXX", ...) is NOT recognized here and is still treated as a potentially real,
        // leaked value.
        $text = (string) preg_replace('/\{\{[A-Za-z0-9_]{1,100}\}\}/u', '_', $text);

        return preg_match('/\bauthorization\s*:\s*bearer\s+\S{8,}/iu', $text) === 1
            || preg_match('/\b(?:api[_ -]?key|x[-_ ]?secret|client[_ -]?secret|(?:access|refresh)[_ -]?token|merchant[_ -]?(?:key|secret|token)|password|mot\s+de\s+passe)\s*[:=]\s*\S{8,}/iu', $text) === 1
            || preg_match('/\b(?:merchant[_ -]?(?:id|identifier|code)|identifiant\s+marchand|code\s+marchand|account[_ -]?(?:id|number|code)|numero\s+de\s+compte)\s*[:=]\s*\S{4,}/iu', $text) === 1
            || preg_match('/\bsk_(?:live|test)?[_-]?[A-Za-z0-9_-]{12,}\b/u', $text) === 1
            || $this->containsPrivateEmail($text)
            || preg_match('/\b(?:telephone|t[ée]l[ée]phone|mobile|msisdn)\s*[:=]\s*\+?[\d ()-]{7,20}\d\b/iu', $text) === 1
            || preg_match('/\b(?:mon|notre)\s+(?:adresse\s+)?ip\s*(?:(?:est|vaut)\s+|[:=]\s*)?(?:\d{1,3}\.){3}\d{1,3}\b/iu', $text) === 1
            || preg_match('/(?<!\d)\d{13,19}(?!\d)/u', preg_replace('/[ -]+/', '', $text) ?? $text) === 1;
    }

    public function requestsSourceCode(string $question): bool
    {
        $question = $this->normalizeForInspection($question);
        $question = Str::ascii(mb_strtolower($question));
        $question = (string) preg_replace(
            '/\b(?:sans|ne\s+pas)\s+(?:me\s+)?(?:faire|generer|ecrire|produire|fournir|donner|montrer|executer)\s+(?:du\s+|de\s+)?code\b|\b(?:ne|n[\'])\s*(?:me\s+)?(?:fais|genere|ecris|produis|fournis|donne|montre|execute)\s+pas\s+(?:du\s+|de\s+)?code\b|\b(?:do\s+not|don[\']t)\s+(?:generate|write|provide|show|execute)\s+(?:any\s+)?code\b/iu',
            '',
            $question,
        );
        $question = (string) preg_replace(
            '/\bcodes?\s+(?:d[\']|de\s+l[\']?)?erreurs?\b|\b(?:http|status)\s+codes?\b|\bcodes?\s+(?:http|de\s+statut)\b/iu',
            'identifiant erreur',
            $question,
        );

        $action = '(?:fai(?:s|re|tes)|veux|voudr(?:ais|ait|ions|iez|aient)|souhait(?:e|er|ez)|execut(?:e|er|ez)|lanc(?:e|er|ez)|gener(?:e|er|ez)|ecri(?:s|re|vez)|reecri(?:s|re|vez)|produi(?:s|re|sez)|fourni(?:s|r|ssez)|donn(?:e|er|ez)|montr(?:e|er|ez)|cre(?:e|er|ez)|implement(?:e|er|ez)|cod(?:e|er|ez)|corrig(?:e|er|ez)|repar(?:e|er|ez)|complet(?:e|er|ez)|continu(?:e|er|ez)|tradui(?:s|re|sez)|converti(?:s|r|ssez)|refactoris(?:e|er|ez)|optimis(?:e|er|ez)|generate|write|rewrite|provide|show|create|implement|code|fix|repair|correct|complete|continue|translate|convert|refactor|execute|run|want|optimise|optimize)';
        $artifact = '(?:codes?(?:\s+source)?|scripts?|snippets?|fonctions?|lambdas?|classes?|programmes?|implementations?|requetes?\s+(?:sql|http)|commandes?(?:\s+(?:shell|bash|curl))?|payloads?|blocs?\s+de\s+code|exemples?\s+(?:de\s+)?(?:code|php|python|javascript|typescript|java)|json|yaml|yml|xml|html|php|python|javascript|typescript|java|c\#|c\+\+|objective-c|go|rust|ruby|cobol|erlang|elixir|smalltalk|swift|kotlin|scala|dart|lua|perl|haskell|clojure|lisp|fortran|solidity|matlab|assembleur|assembly|visual\s+basic|vb(?:\.net)?|groovy|r\b|sql|bash|shell|powershell|laravel|node(?:\.js)?)';
        $implementationArtifact = '(?:controleurs?|middlewares?|handlers?|callbacks?|webhooks?|endpoints?)';

        // A question about a payload's shape -- which fields/parameters a JSON, XML or YAML body
        // must contain -- is asking about the documented API contract, not asking for the
        // payload to be generated, even though it names a data format alongside a common verb
        // like "donner" or "montrer". "Montre comment faire en YAML", with no mention of fields
        // or structure, is not covered by this and still falls through to the checks below.
        if (preg_match('/\b(?:champs?|parametres?|structure|contenu)\b.{0,60}\b(?:json|yaml|yml|xml|html)\b|\b(?:json|yaml|yml|xml|html)\b.{0,60}\b(?:champs?|parametres?|structure|contenu)\b/isu', $question) === 1) {
            return false;
        }

        // A request to see a protocol-level illustration of the documented PVIT API contract --
        // a curl command, a Postman-style HTTP request, or a JSON request/response example -- is
        // asking to see the transport-level shape of a documented call, not asking for
        // application source code to be generated. Only exempted when no actual application
        // artifact (a class, function, script, package, language name, ...) is also named --
        // "ecris une classe PHP qui fait un curl" must still fall through and get blocked below.
        $protocolExampleRequest = '/\b(?:curl|postman)\b|\bjson\b.{0,60}\b(?:exemple|payload|requete|reponse|structure|format|corps|body)\b|\b(?:exemple|payload|requete|reponse|structure|format|corps|body)\b.{0,60}\bjson\b/isu';
        $applicationArtifactMention = '/\b(?:classes?|fonctions?|methodes?|scripts?|programmes?|packages?|paquets?|namespaces?|controleurs?|middlewares?|handlers?|implementations?|lambdas?|snippets?|blocs?\s+de\s+code|codes?\s+sources?|du\s+code|de\s+code|php|python|javascript|typescript|\bjava\b|c#|c\+\+|golang|\bgo\b|rust|kotlin|swift|laravel|symfony|node(?:\.js)?)\b/iu';

        if (preg_match($protocolExampleRequest, $question) === 1
            && preg_match($applicationArtifactMention, $question) !== 1
        ) {
            return false;
        }

        // A request to review or critique code the developer is submitting -- pointing out what
        // is wrong with it -- is not a request to generate, fix or complete code, as long as it
        // does not also ask to fix/correct/complete/rewrite/refactor/translate/implement/create
        // it. "Analyse mon code et dis ce qui ne va pas" is allowed through; "analyse et corrige
        // mon code" still falls through to the mutation-verb checks below and gets blocked. A
        // mutation verb that is itself explicitly negated ("sans le corriger", "sans corriger",
        // "ne le corrige pas") does not count as a mutation request -- it is the very thing the
        // developer is asking us not to do -- so it is neutralized before the check below.
        // Deliberately excludes the bare verb "coder"/"code" -- in French that form is
        // spelled identically to the noun "code" (as in "analyse mon code"), so including it
        // here would make the mutation check trip on the mere topic of the sentence instead of
        // an actual instruction to write/modify something. The other verbs below already cover
        // real mutation intent without that ambiguity.
        $reviewOnlyRequest = '/\b(?:analys(?:e|er|ez)|examin(?:e|er|ez)|revoi(?:s|r|ez)|reli(?:s|re|sez)|verifi(?:e|er|ez)|evalu(?:e|er|ez)|critiqu(?:e|er|ez)|review|check|inspect(?:e|er|ez)?)\b/iu';
        $mutationVerbs = '(?:corrig(?:e|er|ez)|repar(?:e|er|ez)|complet(?:e|er|ez)|refactoris(?:e|er|ez)|reecri(?:s|re|vez)|tradui(?:s|re|sez)|converti(?:s|r|ssez)|cre(?:e|er|ez)|implement(?:e|er|ez)|ecri(?:s|re|vez)|produi(?:s|re|sez)|gener(?:e|er|ez)|fix|repair|correct|complete|rewrite|refactor|translate|create|implement|write|generate|produce)';
        $questionWithoutNegatedMutations = (string) preg_replace(
            '/\bsans\s+(?:le\s+|la\s+|les\s+|l[\']\s*)?'.$mutationVerbs.'\b|\b(?:ne|n[\'])\s*(?:le\s+|la\s+|les\s+|l[\']\s*)?'.$mutationVerbs.'\s+pas\b/iu',
            '',
            $question,
        );

        if (preg_match($reviewOnlyRequest, $questionWithoutNegatedMutations) === 1
            && preg_match('/\b'.$mutationVerbs.'\b/iu', $questionWithoutNegatedMutations) !== 1
        ) {
            return false;
        }

        if (preg_match('/\b(?:code|program)\s+(?:this|that|it|ceci|cela)\b|\bcode-moi\s+ca\b/iu', $question) === 1) {
            return true;
        }

        if (preg_match('/\b(?:implemente|implement)\b.{0,100}\b(?:flux|authentification|authentication|paiement|payment|callback|remboursement|refund)\b/isu', $question) === 1) {
            return true;
        }

        if (preg_match('/\b(?:ecri(?:s|re|vez)|cre(?:e|er|ez)|implement(?:e|er|ez)|cod(?:e|er|ez)|corrig(?:e|er|ez)|complet(?:e|er|ez)|refactoris(?:e|er|ez)|write|create|implement|code|fix|complete|refactor)\b.{0,120}\b'.$implementationArtifact.'\b/isu', $question) === 1) {
            return true;
        }

        if (preg_match('/\b'.$action.'\b.{0,120}\b'.$artifact.'\b/isu', $question) === 1) {
            return true;
        }

        if (preg_match('/\b'.$artifact.'\b.{0,120}\b'.$action.'\b/isu', $question) === 1) {
            return true;
        }

        return preg_match('/\b'.$action.'\b/iu', $question) === 1 && $this->containsSourceCode($question);
    }

    /**
     * Remove only the code-shaped portions of an otherwise natural-language answer, instead of
     * discarding the whole thing. A fenced block is code by construction and is dropped whole,
     * unless it is a documented-API illustration (a fenced curl command, HTTP request or JSON
     * payload/response -- see isDocumentedApiExample()), which is preserved verbatim; every
     * other line is tested independently with the same conservative patterns as
     * containsSourceCode() and dropped only if that single line trips one of them.
     */
    public function stripCodeLikeSegments(string $text): string
    {
        $preserved = [];

        $text = (string) preg_replace_callback(
            '/```([A-Za-z0-9_-]*)[ \t]*\R([\s\S]*?)```|~~~([A-Za-z0-9_-]*)[ \t]*\R([\s\S]*?)~~~/u',
            function (array $matches) use (&$preserved): string {
                $language = $matches[1] !== '' ? $matches[1] : ($matches[3] ?? '');
                $body = $matches[2] !== '' ? $matches[2] : ($matches[4] ?? '');

                if (! $this->isDocumentedApiExample($language, $body)) {
                    return '';
                }

                $token = "\x01PRESERVED_BLOCK_".count($preserved)."\x01";
                $preserved[] = $matches[0];

                return $token;
            },
            $text,
        );

        $lines = preg_split('/\R/u', $text) ?: [];
        $kept = array_filter(
            $lines,
            fn (string $line): bool => str_starts_with(trim($line), "\x01PRESERVED_BLOCK_")
                || ! $this->containsSourceCode($line),
        );

        $result = trim(implode("\n", $kept));

        foreach ($preserved as $index => $block) {
            $result = str_replace("\x01PRESERVED_BLOCK_{$index}\x01", $block, $result);
        }

        return $result;
    }

    /**
     * Whether a fenced block's content is a documented-API illustration (a curl command, a
     * Postman-style HTTP request, or a JSON request/response example) rather than executable
     * application source code. Deliberately narrow: only a single recognizable shape with no
     * other tooling/command mixed in qualifies, so this can never become a laundering route for
     * an actual script.
     */
    private function isDocumentedApiExample(string $language, string $body): bool
    {
        $language = mb_strtolower(trim($language));
        $body = trim($body);

        if ($body === '') {
            return false;
        }

        if (in_array($language, ['json', ''], true) && in_array($body[0], ['{', '['], true)) {
            // A real API doc commonly annotates a JSON example with "// ..." line comments
            // (e.g. "// numero de telephone du client") -- strictly invalid JSON, but a
            // documentary convention, not evidence of code. Try the body as-is first, then
            // retry with such comments stripped before giving up on this candidate.
            foreach ([$body, $this->stripJsonLineComments($body)] as $candidate) {
                try {
                    json_decode($candidate, false, 32, JSON_THROW_ON_ERROR);

                    return true;
                } catch (\JsonException) {
                    // Try the next candidate, or fall through to the curl/HTTP checks below.
                }
            }
        }

        $isSingleCommand = preg_match('/[;&|`]|\$\(/u', $body) !== 1;
        $mentionsOtherTooling = preg_match(
            '/\b(?:wget|npm|npx|composer|php\s+artisan|git|docker|kubectl|sudo|apt(?:-get)?|pip3?|python3?|node|powershell|rm|mv|cp|chmod|chown)\b/iu',
            $body,
        ) === 1;

        if (in_array($language, ['bash', 'sh', 'shell', 'curl', ''], true)
            && $isSingleCommand
            && ! $mentionsOtherTooling
            && preg_match('/\A(?:\$\s*)?curl\s+-/iu', $body) === 1
        ) {
            return true;
        }

        if (in_array($language, ['http', ''], true)
            && $isSingleCommand
            && ! $mentionsOtherTooling
            && (
                // A full request/response line optionally followed by headers ...
                preg_match('/\A(?:GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)\s+\S+(?:\s+HTTP\/\d(?:\.\d)?)?\s*(?:\R(?:[A-Za-z0-9-]+:\s*\S.*)?)*\z/imu', $body) === 1
                // ... or a standalone block of headers only (e.g. illustrating just the
                // required X-Secret/Content-Type headers, without repeating the endpoint).
                || preg_match('/\A(?:[A-Za-z0-9-]+:\s*\S.*(?:\R|\z))+\z/mu', $body) === 1
            )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Strip "// ..." line comments from a JSON-like string, respecting string boundaries so a
     * "//" inside a quoted value (e.g. a URL) is never touched. Iterates by byte rather than by
     * character, which is safe here: every UTF-8 continuation byte is >= 0x80 and can never be
     * mistaken for the ASCII delimiters ('"', '\\', '/', newline) this loop looks for, so
     * multi-byte characters simply pass through unexamined.
     */
    private function stripJsonLineComments(string $json): string
    {
        $result = '';
        $inString = false;
        $escaped = false;
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                $result .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $result .= $char;

                continue;
            }

            if ($char === '/' && ($json[$i + 1] ?? '') === '/') {
                while ($i < $length && $json[$i] !== "\n") {
                    $i++;
                }

                $result .= "\n";

                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    public function containsSourceCode(string $text): bool
    {
        $text = $this->normalizeForInspection($text);

        // Citations are mandatory in model output, but they must not defeat patterns anchored at
        // the end of a code line. Remove only the internal numeric tokens before inspection.
        $text = (string) preg_replace('/\[SOURCE\s*:\s*\d+\s*\]/iu', '', $text);

        // A fenced block that is a documented-API illustration (curl/HTTP/JSON) is not source
        // code -- neutralize it before the generic fenced-block pattern below would otherwise
        // flag its mere presence. Any other fenced block (an actual language, or malformed
        // content) is left untouched and still caught as code.
        $text = (string) preg_replace_callback(
            '/```([A-Za-z0-9_-]*)[ \t]*\R([\s\S]*?)```|~~~([A-Za-z0-9_-]*)[ \t]*\R([\s\S]*?)~~~/u',
            function (array $matches): string {
                $language = $matches[1] !== '' ? $matches[1] : ($matches[3] ?? '');
                $body = $matches[2] !== '' ? $matches[2] : ($matches[4] ?? '');

                return $this->isDocumentedApiExample($language, $body) ? '' : $matches[0];
            },
            $text,
        );

        // Backticks around one atomic API identifier, IP address, host, path or version number
        // are documentary formatting, not source code. Normalize only that narrow form -- a
        // short single token, no whitespace, optionally ending in one "*" for the common
        // prefix-wildcard notation (e.g. "ACC_*", "sk_test_*") -- before applying the
        // conservative code patterns.
        $text = (string) preg_replace(
            '/(?<!`)`([A-Za-z0-9][A-Za-z0-9_.:\/-]{0,126}\*?)`(?!`)/u',
            '$1',
            $text,
        );

        // A moustache-style placeholder token (e.g. "{{secret_key}}", "{{callback_url_code}}")
        // is how the PVIT documentation itself denotes a value to substitute -- documentary
        // formatting, not a code interpolation, so it gets the same narrow treatment as a plain
        // atomic identifier above.
        $text = (string) preg_replace(
            '/(?<!`)`(\{\{[A-Za-z0-9_]{1,100}\}\})`(?!`)/u',
            '$1',
            $text,
        );

        // Backticks around a short HTTP status line or a minimal inline JSON literal are also
        // documentary formatting -- e.g. an example response shape "`HTTP 200`" or a one-field
        // payload "`{ "status": "received" }`" -- not executable source code. Only a span that is
        // actually a valid, bounded JSON value or that narrow HTTP-status shape is normalized
        // here; anything else (a function call, an assignment, a shell command, ...) still falls
        // through to the generic backtick pattern below and is caught as code.
        $text = (string) preg_replace_callback(
            '/(?<!`)`([^`\r\n]{1,200})`(?!`)/u',
            static function (array $matches): string {
                $inner = trim($matches[1]);

                if (preg_match('/\AHTTP\s+\d{3}\z/iu', $inner) === 1) {
                    return $inner;
                }

                if ($inner !== '' && in_array($inner[0], ['{', '['], true)) {
                    try {
                        json_decode($inner, false, 8, JSON_THROW_ON_ERROR);

                        return $inner;
                    } catch (\JsonException) {
                        // Not valid JSON -- keep the backticks so the generic pattern below can
                        // still flag it as code.
                    }
                }

                return $matches[0];
            },
            $text,
        );

        $patterns = [
            '/```|~~~/u',
            '/(?<!`)`[^`\r\n]+`(?!`)/u',
            '/<(?:[!?%\/]|[A-Za-z][^>\r\n]*>)|(?:%|\?)>/u',
            // A bare brace or pipe anywhere in the text used to be enough to flag it as code,
            // but Markdown tables (pipes) and prose mentioning a field name in braces are
            // legitimate documentary output. A Ruby-style block (`{ |x| ... }`) still gets its
            // own narrow pattern below since nothing else here would catch that specific shape.
            '/\{\s*\|[^|\r\n]*\|/u',
            '/(?:->|=>|::=|:=|<-)/u',
            // French-style enumerations end every item but the last with ";" (a normal
            // typographic separator, e.g. "...votre profil ;"). Only flag a trailing semicolon
            // on a line that isn't itself a list item, so this stays aimed at real statements.
            '/^(?!\s*(?:[-*+]|\d+[.)])\s+)[^\r\n]*;\s*(?:(?:\/\/|#)[^\r\n]*)?$/mu',
            '/\b(?:SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|MERGE|GRANT|REVOKE)\b[^;\r\n]*;/iu',
            '/\bSELECT\s+(?:\*|\d+(?:\.\d+)?|["\']|[A-Za-z_][A-Za-z0-9_]*\s*(?:,|\bFROM\b))/iu',
            '/\blambda\s+[A-Za-z_][A-Za-z0-9_]*\s*:/u',
            '/\[[^\]\r\n]+\s+for\s+[A-Za-z_][A-Za-z0-9_]*\s+in\s+[^\]\r\n]+\]/u',
            '/\bnew\s+[A-Z][A-Za-z0-9_]*\s*\(/u',
            // A leading # is also a Markdown heading in documentary answers. Shebangs and
            // preprocessor directives have dedicated patterns below, so only unambiguous
            // comment delimiters belong in this generic code-comment check.
            '/^\s*(?:\/\/|\/\*|\*\/)\s*\S+/mu',
            '/^\s*(?:<\?php|#!\s*\/|<\?xml\b)/imu',
            '/<\/?[A-Za-z][A-Za-z0-9:-]*(?:\s+[^<>]*)?>/u',
            '/^\s*(?:function|class|interface|trait|enum|def)\s+[A-Za-z_][A-Za-z0-9_]*\s*(?:\(|\{|:)/imu',
            '/^\s*(?:fun|func)\s+[A-Za-z_][A-Za-z0-9_]*\s*\(/imu',
            '/^\s*(?:(?:public|private|protected|static|final|abstract|async)\s+)+(?:function\s+)?[A-Za-z_][A-Za-z0-9_]*\s*\(/imu',
            '/^\s*(?:bool(?:ean)?|byte|char|decimal|double|float|int(?:eger)?|long|short|string|unsigned|void|array|object|map|list|set)\s+[A-Za-z_][A-Za-z0-9_]*(?:\s*=|\s*\(|\s*\[|\s*;)/imu',
            '/^\s*(?:from\s+[A-Za-z_][A-Za-z0-9_.]*\s+import\s+|import\s+[A-Za-z_][A-Za-z0-9_., ]*|export\s+(?:default\s+)?)/imu',
            '/^\s*(?:const|let|local|val|var)\s+[A-Za-z_$][A-Za-z0-9_$]*\s*(?::[^=]+)?=/imu',
            '/^\s*(?:namespace|package|using)\s+[A-Za-z_][A-Za-z0-9_.]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*(?:\s*;|\s*$)/imu',
            '/^\s*#\s*(?:define|elif|else|endif|if|include|pragma|undef)\b/imu',
            '/^\s*@[A-Za-z_][A-Za-z0-9_.]*(?:\([^\r\n]*\))?\s*$/mu',
            '/\$[A-Za-z_][A-Za-z0-9_]*(?=\b|->|\[)/u',
            '/^\s*[A-Za-z_$][A-Za-z0-9_$\.\[\]"\']*\s*(?:=|:=|<-|\+=|-=|\*=|\/=)\s*(?!=)\S+/mu',
            '/^\s*(?:if|else\s+if|for|foreach|while|switch|try|catch)\s*(?:\(|\{|.+\bthen\b)/imu',
            '/^\s*(?:return|throw|yield|await)\b.+;?\s*$/imu',
            '/^\s*(?:break|continue|goto|pass)\b[^.!?\r\n]*;?\s*$/imu',
            '/^\s*(?:echo|print|printf|print_r|var_dump|dump|dd|require(?:_once)?|include(?:_once)?|puts)\b.+;?\s*$/imu',
            '/^\s*[A-Za-z_][A-Za-z0-9_]*!\s*\([^\r\n]*\)\s*;?\s*$/mu',
            '/^\s*(?:end|do\s*\|[^|]+\|)\s*$/imu',
            '/^\s*[a-z][a-z0-9_.-]*(?:\s+[^.!?\r\n]+){2,}\s+\.\s*$/mu',
            '/^\s*[A-Z][A-Za-z0-9_]*\s+(?:new|process|handle|execute|run|send|receive|create|update|delete|get|set|post|put|patch|save|find|fetch|call|invoke)(?:\s+[a-z][A-Za-z0-9_]*)*\s*\.\s*$/mu',
            '/^\s*[A-Za-z_][A-Za-z0-9_]*(?:\s+[a-z][A-Za-z0-9_]*)+\s+[a-z][A-Za-z0-9_]*:\s+[^.!?\r\n;]+(?:\s*;\s*[a-z][A-Za-z0-9_]*(?::\s+[^.!?\r\n;]+)?)?\s*\.\s*$/mu',
            '/\b[A-Za-z_][A-Za-z0-9_]*(?:\s+[^,.!?\r\n]+,){1,}\s*[^.!?\r\n]+\s+(?:if|unless|while|until)\s+[A-Za-z_][A-Za-z0-9_]*[?!]\s*$/mu',
            '/^\s*[A-Za-z_$][A-Za-z0-9_$.]*(?:\.[A-Za-z_][A-Za-z0-9_$]*)*\s*\([^\r\n]*\)\s*;?\s*$/mu',
            '/\b[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)+(?:\s*\(|\s+do\b|\s*;\s*$)/mu',
            '/^\s*(?:SELECT\b.+\bFROM\b|INSERT\s+INTO\b|UPDATE\s+\S+\s+SET\b|DELETE\s+FROM\b|CREATE\s+(?:TABLE|INDEX|DATABASE)\b|ALTER\s+TABLE\b|DROP\s+(?:TABLE|INDEX|DATABASE)\b|WITH\s+\S+\s+AS\s*\()/imu',
            '/^\s*(?:\$\s+)?(?:curl|wget|npm|npx|composer|php\s+artisan|git|docker|kubectl|sudo|apt(?:-get)?|pip3?|python3?|node|powershell)\s+\S+/imu',
            '/^\s*(?:GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)\s+\/\S*(?:\s+HTTP\/\d(?:\.\d)?)?\s*$/imu',
            '/^\s*(?:Authorization|Content-Type|Accept|X-[A-Za-z0-9-]+)\s*:\s*\S+/imu',
            '/^\s*(?:FROM|RUN|CMD|ENTRYPOINT|COPY|ADD|ENV|ARG|WORKDIR|EXPOSE)\s+\S+/imu',
            '/^\s*(?:ASSOC|ATTRIB|CALL|CD|CHDIR|CLS|COLOR|COPY|DEL|DIR|ECHO|ENDLOCAL|ERASE|EXIT|FOR|FTYPE|GOTO|IF|MD|MKDIR|MOVE|PATH|PAUSE|POPD|PROMPT|PUSHD|RD|REN|RENAME|RMDIR|SET|SETLOCAL|SHIFT|SHUTDOWN|START|TIME|TITLE|TYPE|VER|VERIFY|VOL)\b/imu',
            '/^\s*(?:MOV|LEA|PUSH|POP|CALL|JMP|CMP|XOR|NOP)\b\s+[^\r\n]+$/imu',
            '/^\s*(?:(?:IF\b.+\b)?MOVE\b.+\bTO\b|PERFORM\b|DISPLAY\b|COMPUTE\b|ACCEPT\b|STOP\s+RUN\b)[^\r\n]*\.\s*$/imu',
            '/^\s*[A-Za-z_][A-Za-z0-9_]*\s+[a-z][A-Za-z0-9_]*:\s+[^\r\n]+\.\s*$/mu',
            '/^\s*begin\b[\s\S]*\bend\.\s*$/iu',
            '/^\s*\([A-Za-z_+*\/=<>!?-][^\r\n]*\)\s*$/mu',
            '/^\s*[A-Za-z_][A-Za-z0-9_:.-]*\s*\([^\r\n]*\)\s*\.\s*$/mu',
            '/^\s*(?:[.#][A-Za-z_][A-Za-z0-9_-]*|[A-Za-z][A-Za-z0-9_-]*(?:\s*[>+~]\s*[A-Za-z.#][A-Za-z0-9_.#-]*)?)\s*\{/mu',
            '/^\s*(?:--[A-Za-z0-9_-]+|color|display|position|margin(?:-[a-z]+)?|padding(?:-[a-z]+)?|background(?:-[a-z]+)?|font(?:-[a-z]+)?|border(?:-[a-z]+)?|grid(?:-[a-z]+)?|flex(?:-[a-z]+)?|width|height|transform|animation|content|opacity|z-index)\s*:\s*[^;\r\n{}]+;\s*$/imu',
            // A unified-diff header is always one line (path right after "---"/"+++"/"@@"), so
            // the whitespace here is deliberately restricted to same-line spaces/tabs -- a bare
            // "\s+" previously spanned the newline after a Markdown horizontal rule ("---") and
            // misfired on that rule immediately followed by a heading or any other line.
            '/^[ \t]*(?:diff[ \t]+--git\b|@@[ \t]+[-+]\d|\+\+\+[ \t]+\S+|---[ \t]+\S+)/imu',
            '/^\s*[+-]\s*(?:const|let|var|function|class|def|import|return|if|for|while)\b/imu',
            '/^\s*["\'][A-Za-z0-9_.:-]+["\']\s*:\s*.+[,}]?\s*$/mu',
            '/^\s*[A-Za-z_][A-Za-z0-9_.-]*\s*:\s*(?:["\'\[{]|true\b|false\b|null\b|-?\d)/imu',
            // Two consecutive "label: value" lines only look like a YAML/config dump when both
            // values are literal-shaped (quoted, bracketed, boolean, null or numeric) -- the same
            // bar as the single-line check just above. Without that bar this also matched two
            // consecutive lines of ordinary prose explaining named fields, e.g. "client_id :
            // votre identifiant" / "client_secret : votre secret", which is a completely normal
            // way to describe API credentials in French and carries no code whatsoever, and which
            // stripCodeLikeSegments() can never repair afterwards since neither line alone trips
            // this two-line pattern (nothing to know is safe to remove).
            '/^\s*[A-Za-z_][A-Za-z0-9_.-]*\s*:\s*(?:["\'\[{]|true\b|false\b|null\b|-?\d)[^\r\n]*\R\s*[A-Za-z_][A-Za-z0-9_.-]*\s*:\s*(?:["\'\[{]|true\b|false\b|null\b|-?\d)/imu',
            '/^\s*[\[{](?s:.*["\'][^"\']+["\']\s*:.*)[\]}]\s*$/u',
            '/^\s*[}\]]\s*[,;]?\s*$/mu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public function enforce(string $text): string
    {
        if (! $this->containsSourceCode($text)) {
            return $text;
        }

        return $this->refusal();
    }

    public function refusal(): string
    {
        return self::SAFE_REFUSAL;
    }

    public function sensitiveDataRefusal(): string
    {
        return self::SENSITIVE_DATA_REFUSAL;
    }

    public function sensitiveOutputRefusal(): string
    {
        return self::SENSITIVE_OUTPUT_REFUSAL;
    }

    private function normalizeForInspection(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        return (string) preg_replace('/\p{Cf}+/u', '', $text);
    }

    private function containsPrivateEmail(string $text): bool
    {
        preg_match_all(
            '/(?<![\pL\pN._%+-])[\pL\pN.!#$%&\'*+\/=?^_`{|}~-]+@[\pL\pN-]+(?:\.[\pL\pN-]+)+(?![\pL\pN.-])/u',
            $text,
            $matches,
        );

        foreach ($matches[0] ?? [] as $email) {
            if (! in_array(mb_strtolower($email), self::PUBLIC_CONTACT_EMAILS, true)) {
                return true;
            }
        }

        return false;
    }
}
