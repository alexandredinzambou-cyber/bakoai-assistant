<?php

namespace App\Services\Rag;

use App\Models\Chunk;
use App\Models\DocumentApi;
use App\Models\KnowledgeVersion;
use App\Models\MoyenPaiement;
use App\Services\Assistant\PromptInjectionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class IngestionService
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly PromptInjectionGuard $promptInjectionGuard,
        private readonly ChunkMetadataExtractor $metadataExtractor,
    ) {}

    public function ingestDirectory(
        string $path,
        string $moyenPaiement = 'PVIT',
        ?string $sourceUrl = null,
        ?string $version = null,
        ?int $knowledgeVersionId = null,
    ): array {
        $files = collect(File::files($path))
            ->filter(fn ($file) => in_array(strtolower($file->getExtension()), ['md', 'html', 'htm', 'txt'], true));

        if ($files->isEmpty()) {
            throw new RuntimeException('Aucune source .md, .html ou .txt à ingérer. Utilisez rag:ingest-openapi pour un fichier OpenAPI JSON.');
        }

        if ($sourceUrl !== null && $files->count() > 1) {
            throw new RuntimeException('--source-url ne peut être appliqué qu’à un seul fichier local.');
        }

        $sources = $files->map(fn ($file): array => [
            'path' => (string) $file->getRealPath(),
            'url' => $this->canonicalOfficialUrl($sourceUrl ?? $this->inferSourceUrl((string) $file->getRealPath())),
        ]);

        $results = [];

        foreach ($sources as $source) {
            $results[] = $this->ingestFile(
                $source['path'],
                $moyenPaiement,
                $source['url'],
                $version,
                $knowledgeVersionId,
            );
        }

        return $results;
    }

    public function ingestFile(
        string $path,
        string $moyenPaiement = 'PVIT',
        ?string $sourceUrl = null,
        ?string $version = null,
        ?int $knowledgeVersionId = null,
        ?callable $afterPersist = null,
    ): array {
        if (! in_array($moyenPaiement, (array) config('rag.documentation_corpora', ['PVIT']), true)) {
            throw new RuntimeException("Corpus documentaire non autorise : {$moyenPaiement}. Utilisez PVIT pour les moyens servis par la passerelle.");
        }

        if ($knowledgeVersionId === null) {
            $this->assertNoActiveKnowledgeVersion();
        } else {
            $knowledgeVersion = KnowledgeVersion::query()->find($knowledgeVersionId);

            if (! $knowledgeVersion || $knowledgeVersion->statut !== 'staging') {
                throw new RuntimeException('La version de connaissance cible doit exister et etre en staging.');
            }
        }

        $maxBytes = (int) config('rag.max_source_bytes', 5 * 1024 * 1024);

        if (! File::exists($path) || File::size($path) > $maxBytes) {
            throw new RuntimeException("Source absente ou superieure a la limite de {$maxBytes} octets.");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            throw new RuntimeException('Le JSON brut n est jamais indexe. Utilisez rag:ingest-openapi pour une specification OpenAPI JSON.');
        }

        if (! in_array($extension, ['md', 'html', 'htm', 'txt'], true)) {
            throw new RuntimeException('Format documentaire non autorise. Utilisez .md, .html, .htm ou .txt.');
        }

        $raw = File::get($path);

        if ($this->isJsonDocument($raw)) {
            throw new RuntimeException('Le JSON brut n est jamais indexe, meme avec une extension differente. Utilisez rag:ingest-openapi.');
        }

        $title = $this->extractTitle($raw, basename($path));
        $sourceUrl ??= $this->inferSourceUrl($path);
        $sourceUrl = $this->canonicalOfficialUrl($sourceUrl);
        $this->assertSourceAbsentFromKnowledgeVersion($knowledgeVersionId, $sourceUrl);
        $linkVerifiedAt = $this->verifyOfficialUrl($sourceUrl) ? now() : null;
        $version ??= $this->inferVersion($sourceUrl);
        $text = $this->normalizeText($raw, $extension);

        if (in_array($extension, ['html', 'htm'], true)) {
            $minChars = max(0, (int) config('rag.min_html_content_chars', 40));
            $length = mb_strlen($text);

            if ($length < $minChars) {
                throw new RuntimeException(
                    "Contenu extrait trop court ({$length} caracteres, minimum {$minChars}) pour {$sourceUrl} : ".
                    'la page est probablement une application rendue cote client (SPA) dont le contenu reel '.
                    "n est genere qu apres execution JavaScript, alors qu un GET brut ne recupere que le squelette HTML. ".
                    'Rendez la page dans un navigateur puis reingerez-la via --path avec --source-url au lieu de --url.',
                );
            }
        }

        if ($this->promptInjectionGuard->containsPromptInjection($text)) {
            throw new RuntimeException('Source mise en quarantaine : instructions de contournement detectees dans le contenu documentaire.');
        }

        $chunks = $this->chunkText($text);

        if ($chunks === []) {
            throw new RuntimeException('La source ne contient aucun texte documentaire exploitable.');
        }

        $maxChunks = max(1, (int) config('rag.max_chunks_per_document', 1000));

        if (count($chunks) > $maxChunks) {
            throw new RuntimeException(
                'Ingestion annulee : la source produit '.count($chunks)." chunks, au-dela de la limite configuree de {$maxChunks} chunks par document.",
            );
        }

        // Embeddings are computed before opening the database transaction. A slow provider must
        // never hold locks, and one failed vector must not leave a half-published document.
        $preparedChunks = [];
        $indexModel = null;

        foreach ($chunks as $position => $chunkData) {
            $chunkText = $chunkData['contenu'];
            $vector = $this->embeddingService->toSqlLiteral(
                $this->embeddingService->embedForDocument($chunkText, $title),
            );
            $model = $this->embeddingService->lastModel();
            $indexModel ??= $model;

            if ($model !== $indexModel) {
                throw new RuntimeException('Ingestion annulee : le fournisseur d embeddings a change pendant le document.');
            }

            $preparedChunks[] = [
                'contenu' => $chunkText,
                'section' => $chunkData['section'] ?: $title,
                'position' => $position + 1,
                'vecteur' => $vector,
                'modele' => $model,
                ...$this->metadataExtractor->extract(
                    $chunkText,
                    $chunkData['section'] ?: $title,
                    $moyenPaiement,
                    $sourceUrl,
                ),
            ];
        }

        $conflict = null;

        return DB::transaction(function () use ($moyenPaiement, $title, $version, $sourceUrl, $linkVerifiedAt, $raw, $preparedChunks, $conflict, $knowledgeVersionId, $afterPersist): array {
            $payment = MoyenPaiement::firstOrCreate(['nom' => $moyenPaiement], ['type' => 'api']);

            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                    ($knowledgeVersionId ?? 'legacy').'|'.$payment->id.'|'.$sourceUrl,
                ]);
            }

            if ($knowledgeVersionId !== null) {
                $knowledgeVersion = KnowledgeVersion::query()->lockForUpdate()->find($knowledgeVersionId);

                if (! $knowledgeVersion || $knowledgeVersion->statut !== 'staging') {
                    throw new RuntimeException('La version de connaissance a quitte le staging pendant l ingestion.');
                }

                $this->assertSourceAbsentFromKnowledgeVersion(
                    $knowledgeVersionId,
                    $sourceUrl,
                    lockForUpdate: true,
                );
            } else {
                $activeVersion = KnowledgeVersion::query()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'statut'])
                    ->firstWhere('statut', 'active');

                if ($activeVersion !== null) {
                    throw new RuntimeException('Import non versionne refuse : une version de connaissance active existe. Ciblez une version staging.');
                }
            }

            // Keep prior chunks immutable because historical responses may cite them. Only the
            // newest version remains eligible for retrieval; a failed transaction reactivates
            // nothing and therefore leaves the previously published source untouched.
            if ($linkVerifiedAt !== null && $knowledgeVersionId === null) {
                DocumentApi::query()
                    ->where('moyen_paiement_id', $payment->id)
                    ->where(function ($query) use ($sourceUrl, $title): void {
                        if ($sourceUrl !== null) {
                            $query->where('lien_officiel', $sourceUrl);
                        } else {
                            $query->where('titre', $title)->whereNull('lien_officiel');
                        }
                    })
                    ->update(['actif' => false]);
            }

            $document = DocumentApi::create([
                'moyen_paiement_id' => $payment->id,
                'knowledge_version_id' => $knowledgeVersionId,
                'titre' => $title,
                'version' => $version,
                'lien_officiel' => $sourceUrl,
                'checksum' => hash('sha256', $raw),
                'langue' => 'fr',
                'actif' => $linkVerifiedAt !== null && $knowledgeVersionId === null,
                'has_known_conflicts' => $conflict !== null,
                'conflict_note' => $conflict,
                'fetched_at' => now(),
                'link_verified_at' => $linkVerifiedAt,
                'date_indexation' => now(),
            ]);

            foreach ($preparedChunks as $prepared) {
                $chunk = Chunk::create([
                    'document_id' => $document->id,
                    'contenu' => $prepared['contenu'],
                    'search_text' => $prepared['search_text'],
                    'section' => $prepared['section'],
                    'section_slug' => $prepared['section_slug'],
                    'content_type' => $prepared['content_type'],
                    'position' => $prepared['position'],
                    'token_count' => $prepared['token_count'],
                    'payment_method' => $prepared['payment_method'],
                    'operation' => $prepared['operation'],
                    'environment' => $prepared['environment'],
                    'http_method' => $prepared['http_method'],
                    'endpoint' => $prepared['endpoint'],
                    'error_code' => $prepared['error_code'],
                    'http_status' => $prepared['http_status'],
                    'status' => $prepared['status'],
                    'metadata' => $prepared['metadata'],
                    'index_terms' => $prepared['index_terms'],
                ]);

                if (DB::getDriverName() === 'pgsql') {
                    DB::insert(
                        'INSERT INTO embeddings (chunk_id, modele, vecteur, created_at, updated_at) VALUES (?, ?, ?::vector, now(), now())',
                        [$chunk->id, $prepared['modele'], $prepared['vecteur']],
                    );
                } else {
                    DB::table('embeddings')->insert([
                        'chunk_id' => $chunk->id,
                        'modele' => $prepared['modele'],
                        'vecteur' => $prepared['vecteur'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $result = [
                'document_id' => $document->id,
                'titre' => $document->titre,
                'chunks' => count($preparedChunks),
                'modele' => $preparedChunks[0]['modele'],
                'has_known_conflicts' => $conflict !== null,
                'actif' => $linkVerifiedAt !== null && $knowledgeVersionId === null,
                'knowledge_version_id' => $knowledgeVersionId,
            ];

            if ($afterPersist !== null) {
                $afterPersist($document, $result);
            }

            return $result;
        });
    }

    private function normalizeText(string $raw, string $extension): string
    {
        if (in_array($extension, ['html', 'htm'], true)) {
            $raw = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $raw) ?? $raw;
            $raw = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $raw) ?? $raw;
            $raw = preg_replace_callback(
                '/<pre\b[^>]*>(.*?)<\/pre>/is',
                fn (array $match): string => "\n\n".$this->metadataExtractor->safeTechnicalProjection($match[1])."\n\n",
                $raw,
            ) ?? $raw;

            // React/Next.js streaming can leave most of the article (parameter tables, response
            // examples, below-the-fold sections) in a hidden Suspense placeholder ("<div hidden
            // id=\"S:0\">...") that is only moved into <main> by client-side hydration. A plain
            // HTTP fetch never runs that JS, so this content has to be recovered explicitly or
            // a short-but-real <main> (e.g. just a title and one sentence) silently passes the
            // length check below while the rest of the page is dropped.
            $streamedContent = $this->extractHiddenStreamedContent($raw);

            // Doc sites wrap the real article in <main>; everything else (search box, command
            // palette hint, sidebar table of contents) is navigation chrome that pollutes chunks
            // and dilutes their embeddings. Prefer isolating <main>; fall back to stripping the
            // known chrome landmarks when a page has no <main>.
            if (preg_match('/<main\b[^>]*>(.*)<\/main>/is', $raw, $match)
                && mb_strlen(trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) >= 100) {
                $raw = $match[1];
            } else {
                // Next.js streamed pages may leave <main> as an empty template and append the
                // rendered article in a hidden sibling. In that case retain the body and remove
                // navigation chrome instead of indexing an empty document.
                $raw = preg_replace('/<(nav|aside|header|footer)\b[^>]*>.*?<\/\1>/is', ' ', $raw) ?? $raw;
            }

            $raw .= $streamedContent;

            $raw = preg_replace('/<h[1-6]\b[^>]*>/i', "\n\n## ", $raw) ?? $raw;
            $raw = preg_replace('/<li\b[^>]*>/i', "\n\n- ", $raw) ?? $raw;
            // A parameter/response reference table has no whitespace between adjacent <td>/<th>
            // cells in the source markup; without a separator, stripping tags glues cell values
            // together into one unreadable word (e.g. "accountOperationCodequerystring...").
            $raw = preg_replace('/<\/t[hd]>\s*<t[hd]\b[^>]*>/i', ' | ', $raw) ?? $raw;
            $raw = preg_replace('/<\/tr>/i', "\n\n", $raw) ?? $raw;
            $raw = preg_replace('/<\/(h1|h2|h3|h4|h5|h6|p|section|article)>/i', "\n\n", $raw) ?? $raw;
            $raw = preg_replace('/<\/li>/i', "\n\n", $raw) ?? $raw;
            $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (in_array($extension, ['md', 'txt'], true)) {
            $raw = preg_replace_callback(
                '/(`{3,}|~{3,})[^\r\n]*\R?(.*?)\1/s',
                fn (array $match): string => "\n\n".$this->metadataExtractor->safeTechnicalProjection($match[2])."\n\n",
                $raw,
            ) ?? $raw;
            $raw = preg_replace('/`([^`\r\n]+)`/u', '$1', $raw) ?? $raw;
        }

        $raw = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\R{3,}/', "\n\n", $raw)) ?? $raw);

        return in_array($extension, ['html', 'htm'], true) ? $this->dropDuplicateLines($raw) : $raw;
    }

    /**
     * Doc sites commonly render a page's data twice in the static HTML — once for a desktop
     * layout (e.g. a <table>) and once for a mobile one (e.g. stacked cards) — toggled with CSS
     * rather than removed from the markup. Stripping tags then leaves every row duplicated
     * verbatim. Drop a later line if an identical one (long enough to not be a coincidental
     * repeated heading or list bullet) already appeared, keeping the first occurrence.
     */
    private function dropDuplicateLines(string $text): string
    {
        $seen = [];
        $kept = [];

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);
            $key = mb_strtolower($trimmed);

            if ($trimmed !== '' && mb_strlen($trimmed) >= 20) {
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * Finds every `<div hidden id="S:0">...</div>` Suspense placeholder Next.js leaves in the
     * server-rendered HTML and returns their concatenated inner markup. A regex cannot match
     * these reliably because they contain further nested <div> tags, so the matching closing
     * tag is found by a simple depth counter instead.
     */
    private function extractHiddenStreamedContent(string $html): string
    {
        $content = '';
        $offset = 0;

        while (($start = stripos($html, '<div hidden id="S:', $offset)) !== false) {
            $tagEnd = strpos($html, '>', $start);

            if ($tagEnd === false) {
                break;
            }

            $innerStart = $tagEnd + 1;
            $cursor = $innerStart;
            $depth = 1;
            $innerEnd = null;

            while ($depth > 0) {
                $nextOpen = stripos($html, '<div', $cursor);
                $nextClose = stripos($html, '</div>', $cursor);

                if ($nextClose === false) {
                    break;
                }

                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    $depth++;
                    $cursor = $nextOpen + 4;
                } else {
                    $depth--;
                    $cursor = $nextClose + 6;

                    if ($depth === 0) {
                        $innerEnd = $nextClose;
                    }
                }
            }

            if ($innerEnd === null) {
                break;
            }

            $content .= "\n\n".substr($html, $innerStart, $innerEnd - $innerStart);
            $offset = $innerEnd + 6;
        }

        return $content;
    }

    private function assertNoActiveKnowledgeVersion(): void
    {
        if (KnowledgeVersion::query()->where('statut', 'active')->exists()) {
            throw new RuntimeException('Import non versionne refuse : une version de connaissance active existe. Ciblez une version staging.');
        }
    }

    private function assertSourceAbsentFromKnowledgeVersion(
        ?int $knowledgeVersionId,
        string $sourceUrl,
        bool $lockForUpdate = false,
    ): void {
        if ($knowledgeVersionId === null) {
            return;
        }

        $query = DocumentApi::query()
            ->where('knowledge_version_id', $knowledgeVersionId)
            ->where('lien_officiel', $sourceUrl);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        if ($query->first(['id']) !== null) {
            throw new RuntimeException('Import refuse : cette source est deja presente dans la version de connaissance cible.');
        }
    }

    private function isJsonDocument(string $raw): bool
    {
        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $trimmed = ltrim($raw);

        if ($trimmed === '' || ! in_array($trimmed[0], ['{', '['], true)) {
            return false;
        }

        try {
            json_decode($trimmed, true, 128, JSON_THROW_ON_ERROR);

            return true;
        } catch (JsonException) {
            return false;
        }
    }

    private function extractTitle(string $raw, string $fallback): string
    {
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $raw, $match)) {
            return Str::limit(trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 180, '');
        }

        if (preg_match('/^\s*#\s+(.+)$/m', $raw, $match)) {
            return Str::limit(trim($match[1]), 180, '');
        }

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $raw, $match)) {
            return Str::limit(trim(html_entity_decode(strip_tags($match[1]))), 180, '');
        }

        return pathinfo($fallback, PATHINFO_FILENAME);
    }

    private function chunkText(string $text): array
    {
        $size = (int) config('rag.chunk_size', 1400);
        $overlap = (int) config('rag.chunk_overlap', 180);
        $blocks = $this->sectionBlocks($text);
        $chunks = [];

        foreach ($blocks as $block) {
            foreach ($this->splitBlockIntoChunks($block['content'], $size, $overlap) as $chunk) {
                $chunk = trim($chunk);

                if ($chunk !== '') {
                    $chunks[] = [
                        'contenu' => $chunk,
                        'section' => $block['section'],
                    ];
                }
            }
        }

        return $chunks;
    }

    /**
     * @return list<array{section:?string, content:string}>
     */
    private function sectionBlocks(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $blocks = [];
        $section = null;
        $current = [];

        $flush = function () use (&$blocks, &$section, &$current): void {
            $content = trim(implode("\n", $current));

            if ($content !== '') {
                $blocks[] = [
                    'section' => $section,
                    'content' => $content,
                ];
            }

            $current = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s*#{1,6}\s+([^\r\n]+)/u', $line, $match) === 1) {
                $flush();
                $section = Str::limit(trim($match[1]), 180, '');
                $current[] = '## '.$section;

                continue;
            }

            $current[] = $line;
        }

        $flush();

        return $blocks === [] ? [['section' => null, 'content' => trim($text)]] : $blocks;
    }

    /**
     * @return list<string>
     */
    private function splitBlockIntoChunks(string $block, int $size, int $overlap): array
    {
        $block = trim($block);

        if ($block === '' || mb_strlen($block) <= $size) {
            return $block === '' ? [] : [$block];
        }

        $chunks = [];
        $cursor = 0;
        $length = mb_strlen($block);

        while ($cursor < $length) {
            $rawChunk = mb_substr($block, $cursor, $size);
            $break = mb_strrpos($rawChunk, "\n");

            if ($break !== false && $break > (int) ($size * 0.45)) {
                $rawChunk = mb_substr($rawChunk, 0, $break);
            }

            $consumed = mb_strlen($rawChunk);
            $chunk = trim($rawChunk);

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            if ($cursor + $consumed >= $length) {
                break;
            }

            $nextCursor = $cursor + max(1, $consumed - min($overlap, max(0, $consumed - 1)));
            $boundaryWindow = mb_substr($block, $nextCursor, max(1, $overlap));
            $boundary = mb_strpos($boundaryWindow, "\n");

            if ($boundary === false) {
                $boundary = mb_strpos($boundaryWindow, ' ');
            }

            $cursor = $boundary === false ? $nextCursor : $nextCursor + $boundary + 1;
        }

        return $chunks;
    }

    private function sectionAt(string $text, int $cursor, string $chunk): ?string
    {
        if (preg_match('/^\s*#{1,6}\s+([^\r\n]+)/u', $chunk, $heading) === 1) {
            return Str::limit(trim($heading[1]), 180, '');
        }

        if (preg_match('/(?:^|\R)\s*#{1,6}\s+([^\r\n]+)/u', $chunk, $heading, PREG_OFFSET_CAPTURE) === 1
            && (int) $heading[0][1] <= 250) {
            return Str::limit(trim($heading[1][0]), 180, '');
        }

        $prefix = mb_substr($text, 0, max(0, $cursor));
        preg_match_all('/^\s*#{1,6}\s+(.+)$/mu', $prefix, $previousHeadings);

        if ($previousHeadings[1] !== []) {
            $lastHeading = $previousHeadings[1][array_key_last($previousHeadings[1])];

            return Str::limit(trim((string) $lastHeading), 180, '');
        }

        return null;
    }

    private function inferSourceUrl(string $path): ?string
    {
        return match (basename($path)) {
            'pvit-register.html' => 'https://docs.mypvit.pro/fr/tutoriels/register',
            'frtutorielsregister.html', 'fr-tutoriels-register.html' => 'https://docs.mypvit.pro/fr/tutoriels/register',
            'pvit-renew-secret.html' => 'https://docs.mypvit.pro/fr/v2/api/renew-secret',
            'frv2apirenew-secret.html', 'fr-v2-api-renew-secret.html' => 'https://docs.mypvit.pro/fr/v2/api/renew-secret',
            'frintrogetting-started.html' => 'https://docs.mypvit.pro/fr/intro/getting-started',
            'fr-intro-getting-started.html' => 'https://docs.mypvit.pro/fr/intro/getting-started',
            'frintrointegration-guide.html' => 'https://docs.mypvit.pro/fr/intro/integration-guide',
            'fr-intro-integration-guide.html' => 'https://docs.mypvit.pro/fr/intro/integration-guide',
            'frintroproduction-guide.html' => 'https://docs.mypvit.pro/fr/intro/production-guide',
            'fr-intro-production-guide.html' => 'https://docs.mypvit.pro/fr/intro/production-guide',
            'frintrointegration-steps.html' => 'https://docs.mypvit.pro/fr/intro/integration-steps',
            'fr-intro-integration-steps.html' => 'https://docs.mypvit.pro/fr/intro/integration-steps',
            default => null,
        };
    }

    private function canonicalOfficialUrl(?string $url): string
    {
        if ($url === null) {
            throw new RuntimeException('Une URL HTTPS officielle est obligatoire pour activer une source documentaire.');
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
            throw new RuntimeException("URL documentaire non autorisee : {$url}");
        }

        $decodedPath = rawurldecode((string) ($parts['path'] ?? '/'));

        if (str_contains($decodedPath, "\0")) {
            throw new RuntimeException("URL documentaire non autorisee : {$url}");
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
        $allowedPathPrefixes = (array) config('rag.allowed_source_path_prefixes', ['/fr/']);

        if (! collect($allowedPathPrefixes)->contains(
            static fn (string $prefix): bool => str_starts_with($path.'/', rtrim($prefix, '/').'/'),
        )) {
            throw new RuntimeException("Chemin documentaire non autorise : {$url}");
        }

        return 'https://'.$host.($path === '/' ? '' : $path);
    }

    private function inferVersion(?string $sourceUrl): ?string
    {
        return $sourceUrl !== null && preg_match('~/v([0-9]+)/~', $sourceUrl, $match) === 1
            ? 'v'.$match[1]
            : null;
    }

    private function verifyOfficialUrl(string $url): bool
    {
        if (! (bool) config('rag.verify_source_urls', true)) {
            return false;
        }

        try {
            $request = Http::accept('text/html, text/markdown, application/json')
                ->withOptions($this->httpOptions())
                ->connectTimeout((int) config('rag.source_connect_timeout', 20))
                ->timeout((int) config('rag.source_timeout', 35))
                ->retry(2, 500, throw: false);
            $response = $request->head($url);

            if (! $response->successful()) {
                $response = $request->withHeader('Range', 'bytes=0-1023')->get($url);
            }
        } catch (\Throwable $exception) {
            Log::warning('Official documentation link verification failed.', [
                'exception' => $exception::class,
                'source_host' => parse_url($url, PHP_URL_HOST),
            ]);
            throw new RuntimeException(
                "Impossible de vérifier le lien officiel : {$url}",
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException("Lien officiel indisponible (HTTP {$response->status()}) : {$url}");
        }

        return true;
    }

    private function httpOptions(): array
    {
        $options = [
            'allow_redirects' => false,
            // The fallback GET only verifies reachability. Streaming prevents a server that
            // ignores the Range header from forcing the full response into PHP memory.
            'stream' => true,
        ];

        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS')) {
            $options['curl'] = [
                CURLOPT_SSL_OPTIONS => defined('CURLSSLOPT_NO_REVOKE') ? CURLSSLOPT_NO_REVOKE : 2,
            ];
        }

        return $options;
    }
}
