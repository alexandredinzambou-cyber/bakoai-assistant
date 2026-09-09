<?php

namespace App\Services\Rag;

use App\Models\DocumentApi;
use App\Models\KnowledgeVersion;
use App\Models\OpenApiSpec;
use App\Services\Assistant\ResponseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class OpenApiIngestionService
{
    private const HTTP_METHODS = [
        'get',
        'put',
        'post',
        'delete',
        'options',
        'head',
        'patch',
        'trace',
    ];

    private const MAX_OPERATIONS = 1000;

    public function __construct(
        private readonly IngestionService $ingestion,
        private readonly ResponseGuard $responseGuard,
    ) {}

    /**
     * @return array{
     *     document_id: int,
     *     open_api_spec_id: int,
     *     titre: string,
     *     chunks: int,
     *     modele: string,
     *     actif: bool,
     *     source_url: string,
     *     openapi_version: string,
     *     api_version: string,
     *     paths: int,
     *     operations: int
     * }
     */
    public function ingestFile(
        string $path,
        string $moyenPaiement,
        string $sourceUrl,
        ?string $version = null,
        ?int $knowledgeVersionId = null,
    ): array {
        $this->assertAllowedPaymentMethod($moyenPaiement);
        $sourceUrl = $this->canonicalOfficialUrl($sourceUrl);

        $maxBytes = (int) config('rag.max_source_bytes', 5 * 1024 * 1024);

        if (! File::isFile($path) || File::size($path) > $maxBytes) {
            throw new RuntimeException("Specification OpenAPI absente ou superieure a {$maxBytes} octets.");
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            throw new RuntimeException('Seules les specifications OpenAPI JSON sont acceptees.');
        }

        $raw = File::get($path);
        $specification = $this->decode($raw);
        $metadata = $this->metadata($specification);
        $this->verifyOfficialSpecification($sourceUrl, $raw);
        $projection = $this->projectSafeProse($specification, $metadata);
        $temporaryPath = $this->writeTemporaryProjection($metadata['title'], $projection);
        $previousActiveDocumentId = $this->activeDocumentId($moyenPaiement, $sourceUrl);

        try {
            $indexed = $this->ingestion->ingestFile(
                $temporaryPath,
                $moyenPaiement,
                $sourceUrl,
                $version ?: $metadata['api_version'],
                $knowledgeVersionId,
            );
        } finally {
            File::delete($temporaryPath);
        }

        try {
            $document = DocumentApi::query()->findOrFail($indexed['document_id']);

            if (($knowledgeVersionId === null && ! $document->actif)
                || ($knowledgeVersionId !== null && (int) $document->knowledge_version_id !== $knowledgeVersionId)
                || $document->link_verified_at === null
                || $document->lien_officiel === null) {
                throw new RuntimeException('La specification OpenAPI n a pas de lien officiel verifie et reste inactive.');
            }

            $openApiSpec = DB::transaction(function () use ($document, $metadata, $raw, $knowledgeVersionId): OpenApiSpec {
                $sourceKey = hash('sha256', $document->lien_officiel);
                $this->acquireSourceLock($document->moyen_paiement_id, $document->lien_officiel);

                $document->refresh();

                if ($knowledgeVersionId === null && ! $document->actif) {
                    throw new RuntimeException('Une version OpenAPI plus recente a ete publiee pendant l ingestion.');
                }

                if ($knowledgeVersionId !== null) {
                    $knowledgeVersion = KnowledgeVersion::query()->lockForUpdate()->find($knowledgeVersionId);

                    if (! $knowledgeVersion
                        || $knowledgeVersion->statut !== 'staging'
                        || (int) $document->knowledge_version_id !== $knowledgeVersionId
                        || $document->actif) {
                        throw new RuntimeException('La version de connaissance OpenAPI a quitte le staging pendant l ingestion.');
                    }
                }

                if ($knowledgeVersionId === null) {
                    OpenApiSpec::query()
                        ->where('moyen_paiement_id', $document->moyen_paiement_id)
                        ->where('source_key', $sourceKey)
                        ->where('actif', true)
                        ->update(['actif' => false]);
                }

                return OpenApiSpec::query()->create([
                    'moyen_paiement_id' => $document->moyen_paiement_id,
                    'document_id' => $document->id,
                    'fichier_url' => $document->lien_officiel,
                    'source_key' => $sourceKey,
                    'format' => 'json',
                    'openapi_version' => $metadata['openapi_version'],
                    'version' => $document->version,
                    'checksum' => hash('sha256', $raw),
                    'paths_count' => $metadata['paths_count'],
                    'operations_count' => $metadata['operations_count'],
                    'actif' => $knowledgeVersionId === null,
                    'date_indexation' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            try {
                $this->compensateFailedPublication(
                    (int) $indexed['document_id'],
                    $previousActiveDocumentId,
                    $sourceUrl,
                );
            } catch (Throwable $compensationException) {
                report($compensationException);

                throw new RuntimeException(
                    'Echec de l ingestion OpenAPI et de sa compensation transactionnelle.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        return [
            'document_id' => $document->id,
            'open_api_spec_id' => $openApiSpec->id,
            'titre' => $document->titre,
            'chunks' => (int) $indexed['chunks'],
            'modele' => (string) $indexed['modele'],
            'actif' => $knowledgeVersionId === null,
            'knowledge_version_id' => $knowledgeVersionId,
            'source_url' => $document->lien_officiel,
            'openapi_version' => $metadata['openapi_version'],
            'api_version' => (string) $document->version,
            'paths' => $metadata['paths_count'],
            'operations' => $metadata['operations_count'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        if (! str_starts_with(ltrim($raw), '{')) {
            throw new RuntimeException('La racine de la specification OpenAPI doit etre un objet JSON.');
        }

        try {
            $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('La specification OpenAPI contient un JSON invalide.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('La racine de la specification OpenAPI doit etre un objet JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $specification
     * @return array{title: string, api_version: string, openapi_version: string, paths_count: int, operations_count: int}
     */
    private function metadata(array $specification): array
    {
        $openApiVersion = null;

        if (is_string($specification['openapi'] ?? null)
            && preg_match('/^3\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9.-]+)?$/', $specification['openapi']) === 1) {
            $openApiVersion = $specification['openapi'];
        } elseif (($specification['swagger'] ?? null) === '2.0') {
            $openApiVersion = '2.0';
        }

        if ($openApiVersion === null) {
            throw new RuntimeException('Version OpenAPI non prise en charge. Utilisez OpenAPI 3.x ou Swagger 2.0.');
        }

        $info = $specification['info'] ?? null;
        $paths = $specification['paths'] ?? null;

        if (! is_array($info) || ! is_array($paths)) {
            throw new RuntimeException('La specification OpenAPI doit definir les objets info et paths.');
        }

        $title = $this->safeProse($info['title'] ?? null, 180);
        $apiVersion = $this->safeProse($info['version'] ?? null, 80);

        if ($title === null || $apiVersion === null) {
            throw new RuntimeException('Les champs info.title et info.version doivent contenir du texte descriptif sur.');
        }

        [$pathsCount, $operationsCount] = $this->validatePathsAndCountOperations($paths, $specification);

        if ($operationsCount === 0) {
            throw new RuntimeException('La specification OpenAPI ne contient aucune operation HTTP exploitable.');
        }

        if ($operationsCount > self::MAX_OPERATIONS) {
            throw new RuntimeException('La specification OpenAPI depasse la limite de '.self::MAX_OPERATIONS.' operations.');
        }

        return [
            'title' => $title,
            'api_version' => $apiVersion,
            'openapi_version' => $openApiVersion,
            'paths_count' => $pathsCount,
            'operations_count' => $operationsCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $specification
     * @param  array{title: string, api_version: string, openapi_version: string, paths_count: int, operations_count: int}  $metadata
     */
    private function projectSafeProse(array $specification, array $metadata): string
    {
        $paragraphs = [
            '# '.$metadata['title'],
            'Cette specification utilise OpenAPI '.$metadata['openapi_version'].' et decrit la version '.$metadata['api_version'].' de l API.',
        ];
        $description = $this->safeProse(data_get($specification, 'info.description'));

        if ($description !== null) {
            $paragraphs[] = $description;
        }

        foreach ($specification['paths'] as $path => $pathItem) {
            if (! is_string($path) || ! is_array($pathItem)) {
                continue;
            }

            $pathItem = $this->resolveLocalReference($pathItem, $specification);

            foreach (self::HTTP_METHODS as $method) {
                $operation = $pathItem[$method] ?? null;

                if (! is_array($operation)) {
                    continue;
                }

                $operation = $this->resolveLocalReference($operation, $specification);
                $paragraphs[] = '## Operation HTTP '.strtoupper($method).' concernant '.$this->describePath($path);
                $summary = $this->safeProse($operation['summary'] ?? null);
                $operationDescription = $this->safeProse($operation['description'] ?? null);

                if ($summary !== null) {
                    $paragraphs[] = $summary;
                }

                if ($operationDescription !== null && $operationDescription !== $summary) {
                    $paragraphs[] = $operationDescription;
                }

                if (($operation['deprecated'] ?? false) === true) {
                    $paragraphs[] = 'Cette operation est declaree obsolete dans la specification officielle.';
                }

                $parameters = [
                    ...$this->arrayValue($pathItem['parameters'] ?? []),
                    ...$this->arrayValue($operation['parameters'] ?? []),
                ];

                foreach ($parameters as $parameter) {
                    if (! is_array($parameter)) {
                        continue;
                    }

                    $this->appendParameter($paragraphs, $this->resolveLocalReference($parameter, $specification));
                }

                $requestBody = $operation['requestBody'] ?? null;

                if (is_array($requestBody)) {
                    $requestBody = $this->resolveLocalReference($requestBody, $specification);
                    $requestDescription = $this->safeProse($requestBody['description'] ?? null);

                    if ($requestDescription !== null) {
                        $paragraphs[] = (($requestBody['required'] ?? false) === true
                            ? 'Le corps de la requete est obligatoire. '
                            : 'Le corps de la requete est facultatif. ').$requestDescription;
                    }
                }

                foreach ($this->arrayValue($operation['responses'] ?? []) as $status => $response) {
                    if (! is_array($response)) {
                        continue;
                    }

                    $response = $this->resolveLocalReference($response, $specification);
                    $responseDescription = $this->safeProse($response['description'] ?? null);
                    $status = $this->safeLabel((string) $status);

                    if ($responseDescription !== null && $status !== null) {
                        $paragraphs[] = 'La reponse de statut '.$status.' signifie ceci : '.$responseDescription;
                    }
                }
            }
        }

        $projection = implode("\n\n", array_values(array_filter($paragraphs)));

        if (mb_strlen($projection) < 100) {
            throw new RuntimeException('La specification OpenAPI ne fournit pas assez de prose documentaire sure a indexer.');
        }

        return $projection;
    }

    /**
     * @param  list<string>  $paragraphs
     * @param  array<string, mixed>  $parameter
     */
    private function appendParameter(array &$paragraphs, array $parameter): void
    {
        $name = $this->safeLabel($parameter['name'] ?? null);
        $location = $this->safeLabel($parameter['in'] ?? null);

        if ($name === null || $location === null) {
            return;
        }

        $description = $this->safeProse($parameter['description'] ?? null);
        $required = ($parameter['required'] ?? false) === true ? 'obligatoire' : 'facultatif';
        $sentence = 'Le parametre nomme '.$name.' est '.$required.' et se trouve dans '.$location.'.';

        if ($description !== null) {
            $sentence .= ' '.$description;
        }

        $paragraphs[] = $sentence;
    }

    private function describePath(string $path): string
    {
        $resources = [];
        $parameters = [];

        foreach (explode('/', trim(rawurldecode($path), '/')) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            if (preg_match('/^\{([^{}]+)\}$/', $segment, $match) === 1) {
                $label = $this->safeLabel($match[1]);

                if ($label !== null) {
                    $parameters[] = $label;
                }

                continue;
            }

            $label = $this->safeLabel(str_replace(['-', '_'], ' ', $segment));

            if ($label !== null) {
                $resources[] = $label;
            }
        }

        $description = $resources === []
            ? 'la racine de l API'
            : 'la ressource '.implode(' puis ', $resources);

        if ($parameters !== []) {
            $description .= ' avec '.(count($parameters) === 1 ? 'le parametre de chemin ' : 'les parametres de chemin ')
                .implode(', ', $parameters);
        }

        return $description.'.';
    }

    /**
     * Resolve JSON Pointer references inside the same document only. External references are
     * deliberately ignored so ingestion never downloads or trusts a second, unapproved source.
     *
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>
     */
    private function resolveLocalReference(array $value, array $root): array
    {
        for ($depth = 0; $depth < 8; $depth++) {
            $reference = $value['$ref'] ?? null;

            if (! is_string($reference) || ! str_starts_with($reference, '#/')) {
                return $value;
            }

            $resolved = $root;

            foreach (explode('/', substr($reference, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

                if (! is_array($resolved) || ! array_key_exists($segment, $resolved)) {
                    return [];
                }

                $resolved = $resolved[$segment];
            }

            if (! is_array($resolved)) {
                return [];
            }

            $value = $resolved;
        }

        return [];
    }

    private function safeProse(mixed $value, int $limit = 1200): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/```.*?```|~~~.*?~~~/s', ' ', $value) ?? $value;
        $value = preg_replace('/<(pre|code)\b[^>]*>.*?<\/\1>/is', ' ', $value) ?? $value;
        $value = preg_replace('/`[^`\r\n]*`/u', ' ', $value) ?? $value;
        $value = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $value) ?? $value;
        $value = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if ($value === ''
            || $this->containsEmbeddedCode($value)
            || $this->responseGuard->containsSourceCode($value)
            || $this->responseGuard->containsSensitiveData($value)) {
            return null;
        }

        return Str::limit($value, $limit, '');
    }

    private function containsEmbeddedCode(string $value): bool
    {
        return preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b\s+\S+/iu', $value) === 1
            || preg_match('/\blambda\s+[A-Za-z_][A-Za-z0-9_]*\s*:/u', $value) === 1
            || preg_match('/\[[^\]\r\n]+\s+for\s+[A-Za-z_][A-Za-z0-9_]*\s+in\s+[^\]\r\n]+\]/iu', $value) === 1
            || preg_match('/\bnew\s+[A-Z][A-Za-z0-9_]*\s*\(/u', $value) === 1;
    }

    private function safeLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/[^\pL\pN_. -]+/u', ' ', $value) ?? $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : Str::limit($value, 100, '');
    }

    /**
     * @return array<mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $paths
     */
    private function validatePathsAndCountOperations(array $paths, array $root): array
    {
        $pathsCount = 0;
        $count = 0;

        foreach ($paths as $path => $pathItem) {
            if (is_string($path) && str_starts_with(strtolower($path), 'x-')) {
                continue;
            }

            if (! is_string($path) || ! str_starts_with($path, '/')) {
                throw new RuntimeException('Chaque chemin OpenAPI doit commencer par une barre oblique.');
            }

            if (! is_array($pathItem)) {
                throw new RuntimeException("Le chemin OpenAPI {$path} doit contenir un objet d operations.");
            }

            $pathItem = $this->resolveLocalReference($pathItem, $root);
            $pathOperations = 0;

            foreach (self::HTTP_METHODS as $method) {
                if (! array_key_exists($method, $pathItem)) {
                    continue;
                }

                $operation = $pathItem[$method];

                if (! is_array($operation)) {
                    throw new RuntimeException("L operation {$method} du chemin {$path} doit etre un objet OpenAPI.");
                }

                $operation = $this->resolveLocalReference($operation, $root);
                $responses = $operation['responses'] ?? null;

                if ($operation === [] || ! is_array($responses) || $responses === []) {
                    throw new RuntimeException("L operation {$method} du chemin {$path} doit etre non vide et definir responses.");
                }

                $pathOperations++;
                $count++;
            }

            if ($pathOperations === 0) {
                throw new RuntimeException("Le chemin OpenAPI {$path} ne contient aucune operation HTTP exploitable.");
            }

            $pathsCount++;
        }

        return [$pathsCount, $count];
    }

    private function writeTemporaryProjection(string $title, string $projection): string
    {
        $directory = storage_path('framework/cache/openapi');
        File::ensureDirectoryExists($directory);
        $filename = (Str::slug($title) ?: 'openapi').'-'.Str::uuid().'.md';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        File::put($path, $projection);

        return $path;
    }

    private function assertAllowedPaymentMethod(string $moyenPaiement): void
    {
        if (! in_array($moyenPaiement, (array) config('rag.documentation_corpora', ['PVIT']), true)) {
            throw new RuntimeException("Corpus documentaire non autorise : {$moyenPaiement}. Utilisez PVIT pour les moyens servis par la passerelle.");
        }
    }

    private function verifyOfficialSpecification(string $url, string $localRaw): void
    {
        if (! (bool) config('rag.verify_source_urls', true)) {
            throw new RuntimeException('La verification distante OpenAPI est obligatoire et ne peut pas etre desactivee.');
        }

        try {
            $response = Http::acceptJson()
                ->withOptions($this->httpOptions())
                ->connectTimeout(max(1, (int) config('rag.source_connect_timeout', 20)))
                ->timeout(max(1, (int) config('rag.source_timeout', 35)))
                ->retry(2, 500, throw: false)
                ->get($url);
        } catch (Throwable $exception) {
            throw new RuntimeException('Impossible de telecharger la specification OpenAPI officielle.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Specification OpenAPI officielle indisponible (HTTP {$response->status()}).");
        }

        $remoteRaw = $response->body();
        $contentType = strtolower((string) $response->header('Content-Type'));
        $leadingBody = strtolower(substr(ltrim($remoteRaw), 0, 32));
        $maxBytes = (int) config('rag.max_source_bytes', 5 * 1024 * 1024);

        if (strlen($remoteRaw) > $maxBytes) {
            throw new RuntimeException("Specification OpenAPI officielle superieure a {$maxBytes} octets.");
        }

        if (str_contains($contentType, 'html')
            || str_starts_with($leadingBody, '<!doctype html')
            || str_starts_with($leadingBody, '<html')) {
            throw new RuntimeException('L URL OpenAPI officielle a retourne du HTML au lieu du JSON attendu.');
        }

        $localCanonical = $this->canonicalJson($localRaw, false);
        $remoteCanonical = $this->canonicalJson($remoteRaw, true);

        if (! hash_equals(hash('sha256', $localCanonical), hash('sha256', $remoteCanonical))) {
            throw new RuntimeException('Le fichier local ne correspond pas a la specification OpenAPI officielle.');
        }
    }

    private function canonicalJson(string $raw, bool $remote): string
    {
        if (! str_starts_with(ltrim($raw), '{')) {
            throw new RuntimeException($remote
                ? 'La specification OpenAPI officielle ne contient pas un objet JSON.'
                : 'La racine de la specification OpenAPI doit etre un objet JSON.');
        }

        try {
            $decoded = json_decode($raw, false, 128, JSON_THROW_ON_ERROR);

            return json_encode(
                $this->sortJsonValue($decoded),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException($remote
                ? 'La specification OpenAPI officielle contient un JSON invalide.'
                : 'La specification OpenAPI contient un JSON invalide.', previous: $exception);
        }
    }

    private function sortJsonValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $sorted = new \stdClass;

            foreach ($properties as $key => $property) {
                $sorted->{$key} = $this->sortJsonValue($property);
            }

            return $sorted;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortJsonValue($item), $value);
        }

        return $value;
    }

    private function activeDocumentId(string $moyenPaiement, string $sourceUrl): ?int
    {
        $id = DocumentApi::query()
            ->whereHas('moyenPaiement', fn ($query) => $query->where('nom', $moyenPaiement))
            ->where('lien_officiel', $sourceUrl)
            ->where('actif', true)
            ->latest('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function compensateFailedPublication(
        int $failedDocumentId,
        ?int $previousActiveDocumentId,
        string $sourceUrl,
    ): void {
        $identity = DocumentApi::query()
            ->select(['id', 'moyen_paiement_id', 'lien_officiel'])
            ->find($failedDocumentId);

        if ($identity === null || $identity->lien_officiel !== $sourceUrl) {
            return;
        }

        DB::transaction(function () use ($identity, $failedDocumentId, $previousActiveDocumentId, $sourceUrl): void {
            $paymentId = (int) $identity->moyen_paiement_id;
            $this->acquireSourceLock($paymentId, $sourceUrl);
            $failedDocument = DocumentApi::query()->lockForUpdate()->find($failedDocumentId);

            if ($failedDocument === null
                || (int) $failedDocument->moyen_paiement_id !== $paymentId
                || $failedDocument->lien_officiel !== $sourceUrl) {
                return;
            }

            $failedDocument->delete();

            $activeDocument = DocumentApi::query()
                ->where('moyen_paiement_id', $paymentId)
                ->where('lien_officiel', $sourceUrl)
                ->where('actif', true)
                ->lockForUpdate()
                ->first();

            if ($activeDocument !== null) {
                return;
            }

            $sourceKey = hash('sha256', $sourceUrl);
            $lastPublishedSpecDocumentId = OpenApiSpec::query()
                ->where('moyen_paiement_id', $paymentId)
                ->where('source_key', $sourceKey)
                ->where('actif', true)
                ->latest('id')
                ->value('document_id');
            $candidate = null;

            if ($lastPublishedSpecDocumentId !== null) {
                $candidate = DocumentApi::query()
                    ->whereKey($lastPublishedSpecDocumentId)
                    ->where('moyen_paiement_id', $paymentId)
                    ->where('lien_officiel', $sourceUrl)
                    ->whereNotNull('link_verified_at')
                    ->lockForUpdate()
                    ->first();
            }

            $candidate ??= DocumentApi::query()
                ->where('moyen_paiement_id', $paymentId)
                ->where('lien_officiel', $sourceUrl)
                ->whereNotNull('link_verified_at')
                ->latest('date_indexation')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($candidate === null && $previousActiveDocumentId !== null) {
                $candidate = DocumentApi::query()
                    ->whereKey($previousActiveDocumentId)
                    ->where('moyen_paiement_id', $paymentId)
                    ->where('lien_officiel', $sourceUrl)
                    ->whereNotNull('link_verified_at')
                    ->lockForUpdate()
                    ->first();
            }

            if ($candidate === null) {
                return;
            }

            $candidate->update(['actif' => true]);
            $activeSpecExists = OpenApiSpec::query()
                ->where('moyen_paiement_id', $paymentId)
                ->where('source_key', $sourceKey)
                ->where('actif', true)
                ->exists();

            if (! $activeSpecExists) {
                OpenApiSpec::query()
                    ->where('document_id', $candidate->id)
                    ->update(['actif' => true]);
            }
        });
    }

    private function acquireSourceLock(int $paymentId, string $sourceUrl): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$paymentId.'|'.$sourceUrl]);
        }
    }

    private function canonicalOfficialUrl(string $url): string
    {
        if (mb_strlen(trim($url)) > 255) {
            throw new RuntimeException('URL OpenAPI trop longue pour le stockage configure.');
        }

        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = array_map('strtolower', (array) config('rag.allowed_source_hosts', []));

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || ! in_array($host, $allowedHosts, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new RuntimeException("URL OpenAPI officielle non autorisee : {$url}");
        }

        $decodedPath = rawurldecode((string) ($parts['path'] ?? '/'));

        if (str_contains($decodedPath, "\0")) {
            throw new RuntimeException("URL OpenAPI officielle non autorisee : {$url}");
        }

        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $decodedPath)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = rawurlencode($segment);
        }

        $path = '/'.implode('/', $segments);

        return 'https://'.$host.($path === '/' ? '' : $path);
    }

    private function httpOptions(): array
    {
        $options = ['allow_redirects' => false];

        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS')) {
            $options['curl'] = [
                CURLOPT_SSL_OPTIONS => defined('CURLSSLOPT_NO_REVOKE') ? CURLSSLOPT_NO_REVOKE : 2,
            ];
        }

        return $options;
    }
}
