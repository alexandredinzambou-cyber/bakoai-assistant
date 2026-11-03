<?php

namespace App\Services\Rag;

use Illuminate\Support\Str;

final class ChunkMetadataExtractor
{
    private const ENDPOINT_PATH = '\/[A-Za-z0-9_{}.:\~\-]+(?:\/[A-Za-z0-9_{}.:\~\-]+)*';

    private const HTTP_METHOD_WITH_ENDPOINT = '~(?:^|\s)(?<method>GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)\s+(?:https?://[^/\s]+)?(?<endpoint>'.self::ENDPOINT_PATH.')(?=[\s?#),;]|$)~imu';

    private const STANDALONE_ENDPOINT = '~(?<![A-Za-z0-9_{}.:\~\-])(?:https?://[^/\s]+)?(?<endpoint>'.self::ENDPOINT_PATH.')(?=[\s?#),;]|$)~iu';

    private const HTTP_STATUS = '/\b(?:HTTP(?:\/\d(?:\.\d)?)?|statut(?:\s+HTTP)?|status)\s*[:=]?\s*([1-5][0-9]{2})\b/iu';

    private const REQUEST_URL = '~https?://[^\s\'"<>]+~i';

    private const CURL_METHOD = '~(?:--request|-X)\s+[\'"]?([A-Za-z]+)~i';

    private const CURL_DATA_KEYVALUE = '~--data(?:-urlencode|-raw|-binary)?\s+([\'"])([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\1~i';

    private const CURL_HEADER_KEYVALUE = '~(?:--header|-H)\s+([\'"])([A-Za-z][A-Za-z0-9-]*)\s*:\s*(.*?)\1~i';

    private const RAW_HEADER_KEYVALUE_LINE = '~^([A-Za-z][A-Za-z0-9-]{1,40}):\s+(\S[^\r\n]*)$~m';

    private const JSON_FIELD_KEYVALUE = '~"([A-Za-z_][A-Za-z0-9_]*)"\s*:\s*("[^"]*"|-?\d+(?:\.\d+)?|true|false|null)~i';

    /**
     * @return array{
     *   token_count:int,
     *   payment_method:string,
     *   section_slug:?string,
     *   content_type:string,
     *   search_text:string,
     *   index_terms:list<string>,
     *   operation:?string,
     *   environment:?string,
     *   http_method:?string,
     *   endpoint:?string,
     *   error_code:?string,
     *   http_status:?int,
     *   status:string,
     *   metadata:array<string, mixed>
     * }
     */
    public function extract(
        string $content,
        ?string $section,
        string $paymentMethod,
        ?string $sourceUrl = null,
        ?string $documentTitle = null,
    ): array {
        $searchable = trim(($section ?? '')."\n".$content);
        $normalized = Str::ascii(mb_strtolower($searchable));
        $sectionSlug = $this->sectionSlug($section);
        $pagePath = $this->officialPagePath($sourceUrl);
        $operation = $pagePath !== null
            ? $this->operationFromPage($pagePath, $sectionSlug ?? '')
            : $this->operation($normalized, $sectionSlug ?? '');
        $environment = $this->environment($normalized);
        $httpMethod = $this->httpMethod($searchable);
        $endpoint = $this->endpoint($searchable);
        $errorCode = $this->errorCode($searchable);
        $httpStatus = $this->httpStatus($searchable);
        $contentType = ($pagePath !== null ? $this->contentTypeFromPage($pagePath, $sectionSlug ?? '') : null)
            ?? $this->contentType($normalized, $sectionSlug, $operation, $httpMethod, $endpoint, $errorCode, $httpStatus);
        $indexTerms = $this->indexTerms($contentType, $operation, $environment, $httpMethod, $endpoint, $errorCode, $httpStatus);

        return [
            'token_count' => $this->tokenCount($content),
            'payment_method' => $paymentMethod,
            'section_slug' => $sectionSlug,
            'content_type' => $contentType,
            'search_text' => $this->searchText($content, $section, $contentType, $indexTerms, $documentTitle),
            'index_terms' => $indexTerms,
            'operation' => $operation,
            'environment' => $environment,
            'http_method' => $httpMethod,
            'endpoint' => $endpoint,
            'error_code' => $errorCode,
            'http_status' => $httpStatus,
            'status' => 'active',
            'metadata' => array_filter([
                'source_url' => $sourceUrl,
                'extractor_version' => '3',
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    /**
     * Keep only non-executable technical facts from a code-shaped documentation block:
     * HTTP method, endpoint, status codes, and the field/header NAMES the example transmits.
     * Raw syntax and commands (curl flags, shell punctuation) are discarded, and so are the
     * literal values those fields/headers carry -- an ingested doc example can contain a real
     * secret, token, or card number, so only names ever survive into the indexed projection.
     */
    public function safeTechnicalProjection(string $content): string
    {
        $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = (string) preg_replace('/\p{Cf}+/u', '', $content);
        $facts = [];
        $hasExplicitMethodAndEndpoint = false;

        if (preg_match_all(self::HTTP_METHOD_WITH_ENDPOINT, $content, $requests, PREG_SET_ORDER) !== false) {
            foreach ($requests as $request) {
                $method = strtoupper((string) $request['method']);
                $endpoint = $this->cleanEndpoint((string) $request['endpoint']);

                if ($endpoint !== null) {
                    $facts['request:'.$method.':'.$endpoint] = "Methode HTTP et endpoint documentes : {$method} {$endpoint}.";
                    $hasExplicitMethodAndEndpoint = true;
                }
            }
        }

        // curl/HTTP examples built around a full request URL rarely spell out "METHOD
        // /path" as inline prose; recover the endpoint from the URL itself instead.
        if (! $hasExplicitMethodAndEndpoint && preg_match(self::REQUEST_URL, $content, $urlMatch) === 1) {
            $endpoint = $this->cleanEndpoint((string) (parse_url($urlMatch[0], PHP_URL_PATH) ?? ''));

            if ($endpoint !== null) {
                $method = preg_match(self::CURL_METHOD, $content, $methodMatch) === 1
                    ? strtoupper($methodMatch[1])
                    : null;
                $facts['url:endpoint'] = $method !== null
                    ? "Methode HTTP et endpoint documentes : {$method} {$endpoint}."
                    : "Endpoint documente : {$endpoint}.";
            }
        }

        $fields = [];

        if (preg_match_all(self::CURL_DATA_KEYVALUE, $content, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $fields[$match[2]] = true;
            }
        }

        if (preg_match_all(self::JSON_FIELD_KEYVALUE, $content, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $fields[$match[1]] ??= true;
            }
        }

        if ($fields !== []) {
            $fieldNames = array_slice(array_keys($fields), 0, 15);
            $facts['fields'] = 'Champs documentes dans cet exemple : '.implode(', ', $fieldNames).'.';
        }

        $headers = [];

        if (preg_match_all(self::CURL_HEADER_KEYVALUE, $content, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $headers[$match[2]] = true;
            }
        }

        if (preg_match_all(self::RAW_HEADER_KEYVALUE_LINE, $content, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $headers[$match[1]] ??= true;
            }
        }

        if ($headers !== []) {
            $facts['headers'] = 'En-tetes documentes dans cet exemple : '.implode(', ', array_keys($headers)).'.';
        }

        if (preg_match_all(self::HTTP_STATUS, $content, $statuses) !== false) {
            foreach ($statuses[1] ?? [] as $status) {
                $status = (int) $status;
                $facts['status:'.$status] = "Statut HTTP : {$status}.";
            }
        }

        return implode("\n", array_values($facts));
    }

    private function sectionSlug(?string $section): ?string
    {
        $slug = Str::slug(Str::ascii((string) $section));

        return $slug === '' ? null : Str::limit($slug, 180, '');
    }

    private function contentType(
        string $normalized,
        ?string $sectionSlug,
        ?string $operation,
        ?string $httpMethod,
        ?string $endpoint,
        ?string $errorCode,
        ?int $httpStatus,
    ): string {
        $sectionSlug ??= '';

        // A reference table of error codes ("401 | AUTHENTICATION_FAILED | ...") reads, to the
        // keyword checks below, as whatever incidental word one of its many rows happens to
        // contain -- a row mentioning "header" would otherwise fall into 'parameters', one
        // mentioning "callback" into 'callback' -- so a section explicitly about errors must be
        // classified before any of that scanning runs. The section title is a reliable, low-noise
        // signal here in a way the row-by-row content never is.
        if (str_contains($sectionSlug, 'erreur') || str_contains($sectionSlug, 'error')) {
            return 'error';
        }

        if (preg_match('/\b(?:parametres? requis|champs?|donnees?|payload|body|corps|x secret|api secret|mot de passe|code url|compte d operation|cle secrete|cle d acces|header|entete)\b/u', $normalized.' '.$sectionSlug) === 1) {
            return 'parameters';
        }

        if ($errorCode !== null || $httpStatus !== null || $operation === 'gestion des erreurs') {
            return 'error';
        }

        if ($operation === 'callback' || preg_match('/\b(?:callback|webhook|notification|accuse de reception)\b/u', $normalized.' '.$sectionSlug) === 1) {
            return 'callback';
        }

        if ($operation === 'authentification' || preg_match('/\b(?:authentification|authentication|token|secret|x secret|cle d acces)\b/u', $normalized.' '.$sectionSlug) === 1) {
            return 'authentication';
        }

        if ($httpMethod !== null || $endpoint !== null || preg_match('/\b(?:requete|request|endpoint|api)\b/u', $normalized) === 1) {
            return 'request';
        }

        if ($operation === 'inscription') {
            return 'registration';
        }

        if (preg_match('/\b(?:simulation|scenario|test)\b/u', $normalized.' '.$sectionSlug) === 1) {
            return 'simulation';
        }

        if (preg_match('/\b(?:etape|etapes|checklist|guide|procedure|processus)\b/u', $normalized.' '.$sectionSlug) === 1) {
            return 'guide_step';
        }

        return 'prose';
    }

    /**
     * @return list<string>
     */
    private function indexTerms(
        string $contentType,
        ?string $operation,
        ?string $environment,
        ?string $httpMethod,
        ?string $endpoint,
        ?string $errorCode,
        ?int $httpStatus,
    ): array {
        $terms = [
            $contentType,
            $operation,
            $environment,
            $httpMethod,
            $endpoint,
            $errorCode,
            $httpStatus !== null ? (string) $httpStatus : null,
        ];

        return collect($terms)
            ->filter(static fn (mixed $term): bool => is_string($term) && trim($term) !== '')
            ->map(static fn (string $term): string => Str::squish(Str::ascii(mb_strtolower($term))))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Enrich the lexical index without changing the source excerpt shown to users.
     */
    /**
     * Enrich the lexical index without changing the source excerpt shown to users: the document
     * title and the section path make sibling sections of different pages ("En-tetes" of the
     * payment API vs. of the refund API) distinguishable. Only this chunk's own structural facts
     * are added -- no generic synonym lists, which used to put "mot de passe" or "payload" in the
     * index of a hundred chunks that never mention them.
     */
    private function searchText(string $content, ?string $section, string $contentType, array $indexTerms, ?string $documentTitle = null): string
    {
        return Str::squish(implode(' ', array_filter([
            $documentTitle,
            $section,
            $content,
            $contentType,
            implode(' ', $indexTerms),
        ])));
    }

    // Path of an official documentation page ("api/payment", "tutoriels/callback"), or null.
    private function officialPagePath(?string $sourceUrl): ?string
    {
        if (! is_string($sourceUrl) || preg_match('~^https://docs\.mypvit\.pro/fr/(.+?)/?$~', $sourceUrl, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]);
    }

    /**
     * The documentation's own structure is a far more reliable subject signal than keywords in
     * the text (a webhook payload lists "champs", an error table mentions "callback"): the page
     * says what the chunk is about, the section heading says what kind of content it is.
     */
    private function contentTypeFromPage(string $pagePath, string $sectionSlug): ?string
    {
        $page = explode('/', $pagePath)[0];

        return match (true) {
            $page === 'errors' => 'error',
            $page === 'webhooks', $pagePath === 'tutoriels/callback' => 'callback',
            $pagePath === 'tutoriels/register' => 'registration',
            str_starts_with($sectionSlug, 'exemple') => 'example',
            str_starts_with($sectionSlug, 'reponse') => 'response',
            $page === 'api' && preg_match('/^(en-tetes|parametres|corps)/', $sectionSlug) === 1 => 'parameters',
            $page === 'api' => 'request',
            $page === 'intro' || $pagePath === 'tutoriels/merchant-journey' => $this->guideSectionType($sectionSlug),
            in_array($page, ['tutoriels', 'plugins'], true) => 'guide_step',
            default => null,
        };
    }

    // Multi-topic guide pages: the section heading names the subject.
    private function guideSectionType(string $sectionSlug): string
    {
        $heading = str_replace('-', ' ', $sectionSlug);

        return match (true) {
            preg_match('/\b(?:callback|webhook|accuse de reception)\b/', $heading) === 1 => 'callback',
            preg_match('/\b(?:simulation|scenario)\b/', $heading) === 1 => 'simulation',
            preg_match('/\b(?:authentification|token|cle secrete|renew secret)\b/', $heading) === 1 => 'authentication',
            preg_match('/\b(?:erreur|error)\b/', $heading) === 1 => 'error',
            default => 'guide_step',
        };
    }

    private function operationFromPage(string $pagePath, string $sectionSlug): ?string
    {
        $byPage = [
            'api/payment' => 'paiement', 'api/split-payment' => 'paiement', 'api/link' => 'paiement',
            'api/qrcode' => 'paiement', 'api/status' => 'paiement', 'api/fees' => 'paiement',
            'api/refund' => 'remboursement',
            'api/renew-secret' => 'renouvellement de secret',
            'webhooks/status' => 'callback', 'tutoriels/callback' => 'callback',
            'errors/codes' => 'gestion des erreurs',
            'tutoriels/register' => 'inscription',
            'plugins/woocommerce' => "méthode d'intégration", 'plugins/prestashop' => "méthode d'intégration",
            'tutoriels/woocommerce' => "méthode d'intégration",
        ];

        if (isset($byPage[$pagePath])) {
            return $byPage[$pagePath];
        }

        // Overview and guide pages cover several operations: only their section heading decides.
        if (str_starts_with($pagePath, 'intro/') || $pagePath === 'tutoriels/merchant-journey') {
            $heading = str_replace('-', ' ', $sectionSlug);

            return match (true) {
                preg_match('/\b(?:callback|webhook|accuse de reception)\b/', $heading) === 1 => 'callback',
                preg_match('/\b(?:authentification|token|renew secret|cle secrete)\b/', $heading) === 1 => 'authentification',
                preg_match('/\b(?:rembourse\w*|refund)\b/', $heading) === 1 => 'remboursement',
                preg_match('/\b(?:simulation|paiement|payment|transaction)\b/', $heading) === 1 => 'paiement',
                preg_match('/\b(?:erreur|error)\b/', $heading) === 1 => 'gestion des erreurs',
                preg_match('/\b(?:inscri\w*|compte marchand)\b/', $heading) === 1 => 'inscription',
                default => null,
            };
        }

        return null;
    }

    private function tokenCount(string $content): int
    {
        preg_match_all('/[\p{L}\p{N}_-]+|[^\s\p{L}\p{N}]/u', $content, $matches);

        return count($matches[0] ?? []);
    }

    private function operation(string $normalized, string $sectionSlug): ?string
    {
        // A reference table of error codes packs several unrelated operations' keywords into
        // its own rows ("This callback URL is invalid", "This transaction type is not allowed")
        // purely as row descriptions -- the keyword scan below would tag it 'callback' or
        // 'paiement' off whichever row happens to come first, instead of 'gestion des erreurs'.
        // A section titled for errors is a reliable enough signal to short-circuit that.
        if (str_contains($sectionSlug, 'erreur') || str_contains($sectionSlug, 'error')) {
            return 'gestion des erreurs';
        }

        $patterns = [
            'renouvellement de secret' => '/\b(?:renew secret|renew-secret|renouvellement du secret|renouveler le secret)\b/u',
            'authentification' => '/\b(?:authentification|authentication|authentifier|jeton d acces|access token)\b/u',
            'inscription' => '/\b(?:inscription|register|enregistrement du marchand|compte marchand)\b/u',
            'callback' => '/\b(?:callback|webhook|notification serveur|accuse de reception)\b/u',
            'remboursement' => '/\b(?:remboursement|rembourser|refund)\b/u',
            'paiement' => '/\b(?:initiation de paiement|paiement|payment|transaction)\b/u',
            'gestion des erreurs' => '/\b(?:gestion des erreurs|code d erreur|error code|erreur http|statut http)\b/u',
        ];

        foreach ($patterns as $operation => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return $operation;
            }
        }

        return null;
    }

    private function environment(string $normalized): ?string
    {
        $hasSandbox = preg_match('/\b(?:sandbox|bac a sable|environnement de test)\b/u', $normalized) === 1;
        $hasProduction = preg_match('/\b(?:production|prod|mise en production)\b/u', $normalized) === 1;

        return match (true) {
            $hasSandbox && ! $hasProduction => 'sandbox',
            $hasProduction && ! $hasSandbox => 'production',
            default => null,
        };
    }

    private function httpMethod(string $content): ?string
    {
        return preg_match(self::HTTP_METHOD_WITH_ENDPOINT, $content, $match) === 1
            ? strtoupper($match['method'])
            : null;
    }

    private function endpoint(string $content): ?string
    {
        if (preg_match(self::HTTP_METHOD_WITH_ENDPOINT, $content, $match) === 1) {
            return $this->cleanEndpoint((string) $match['endpoint']);
        }

        if (preg_match(self::STANDALONE_ENDPOINT, $content, $match) !== 1) {
            return null;
        }

        return $this->cleanEndpoint((string) $match['endpoint']);
    }

    private function errorCode(string $content): ?string
    {
        if (preg_match('/\b(?:code\s+d[\x{2019}\x{0027}]?erreur|error\s+code)\s*[:=]?\s*([A-Z][A-Z0-9_.-]{1,119})\b/iu', $content, $match) !== 1) {
            return null;
        }

        return strtoupper($match[1]);
    }

    private function httpStatus(string $content): ?int
    {
        if (preg_match(self::HTTP_STATUS, $content, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    private function cleanEndpoint(string $endpoint): ?string
    {
        $endpoint = rtrim($endpoint, '.,;:)');

        return $endpoint === '' ? null : Str::limit($endpoint, 500, '');
    }
}
