<?php

return [
    // TEMPORARY test switch: when true, AssistantService skips CitationGuard::validate() entirely
    // and shows the model's raw synthesized answer as-is. This removes the anti-hallucination
    // check (unsupported claims, invented links, malformed citations all pass through unchecked).
    // Off by default; only meant to be flipped on for a short, deliberate local comparison test.
    'citation_guard_disabled' => (bool) env('CITATION_GUARD_DISABLED', false),

    'embedding_dimensions' => env('RAG_EMBEDDING_DIMENSIONS', env('EMBEDDING_DIMENSIONS', 768)),
    'chunk_size' => env('RAG_CHUNK_SIZE', 1400),
    'chunk_overlap' => env('RAG_CHUNK_OVERLAP', 180),
    'top_k' => env('RAG_TOP_K', 5),
    'rrf_rank_constant' => env('RAG_RRF_RANK_CONSTANT', 60),
    'min_confidence' => env('RAG_MIN_CONFIDENCE', 0.35),
    'llm_confidence_floor' => env('RAG_LLM_CONFIDENCE_FLOOR', 0.30),
    // Extractive/assistive fallback: how much a second chunk is favored for merely continuing
    // the same document as the best match, versus a competing chunk from another document. Kept
    // well below the overlap-count step (100 per matched token) in fallbackChunkScore() so it
    // only breaks near-ties in favor of narrative coherence -- it must never let a same-document
    // chunk outrank a clearly better-matching chunk from a different, more on-topic document.
    'fallback_same_document_bonus' => (float) env('RAG_FALLBACK_SAME_DOCUMENT_BONUS', 3.0),
    // Internal gating (min_confidence / llm_confidence_floor above) always stays active.
    // This only controls whether the confidence/confidence_level/confidence_score fields
    // are exposed to API consumers and rendered as the "Confiance NN %" badge in the UI.
    'expose_confidence' => (bool) env('RAG_EXPOSE_CONFIDENCE', true),
    'embedding_model' => env('RAG_EMBEDDING_MODEL', 'nvidia/llama-nemotron-embed-vl-1b-v2'),
    'sources_path' => env('RAG_SOURCES_PATH', storage_path('app/sources')),
    'max_source_bytes' => (int) env('RAG_MAX_SOURCE_BYTES', 5 * 1024 * 1024),
    // Below this, an HTML source almost certainly failed to yield real content -- the
    // tell-tale sign of a client-side-rendered (SPA) page fetched with a plain GET, which
    // leaves only chrome/title text behind (e.g. "PVit Docs", ~9 chars) instead of the article.
    'min_html_content_chars' => (int) env('RAG_MIN_HTML_CONTENT_CHARS', 40),
    'max_chunks_per_document' => (int) env('RAG_MAX_CHUNKS_PER_DOCUMENT', 1000),
    'verify_source_urls' => (bool) env('RAG_VERIFY_SOURCE_URLS', true),
    'source_connect_timeout' => (int) env('RAG_SOURCE_CONNECT_TIMEOUT', 20),
    'source_timeout' => (int) env('RAG_SOURCE_TIMEOUT', 35),
    'allowed_source_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('RAG_ALLOWED_SOURCE_HOSTS', 'docs.mypvit.pro')),
    ))),
    'allowed_source_path_prefixes' => array_values(array_filter(array_map(
        static fn (string $prefix): string => '/'.trim($prefix, '/'),
        explode(',', env('RAG_ALLOWED_SOURCE_PATH_PREFIXES', '/fr/,/openapi/')),
    ))),
    'admin_key' => env('RAG_ADMIN_KEY'),
    // PVIT owns the public integration contract. The other values are payment
    // contexts exposed through that gateway, not independent documentation
    // corpora.
    'documentation_corpus_payment_method' => 'PVIT',
    'documentation_corpora' => ['PVIT'],
    'payment_methods' => ['PVIT', 'Airtel Money', 'Moov Money', 'Visa', 'Mastercard', 'GIMAC'],
    'gateway_payment_contexts' => ['Airtel Money', 'Moov Money', 'Visa', 'Mastercard', 'GIMAC'],
    'documentation_scope_by_payment_method' => [
        'PVIT' => 'PVIT',
        'Airtel Money' => 'PVIT',
        'Moov Money' => 'PVIT',
        'Visa' => 'PVIT',
        'Mastercard' => 'PVIT',
        'GIMAC' => 'PVIT',
    ],
    'payment_method_aliases' => [
        'PVIT' => ['pvit', 'mypvit', 'my pvit'],
        'Airtel Money' => ['airtel money', 'airtelmoney', 'air tel money', 'airtel'],
        'Moov Money' => ['moov money', 'moovmoney', 'moov'],
        'Visa' => ['visa'],
        'Mastercard' => ['mastercard', 'master card'],
        'GIMAC' => ['gimac', 'gi mac'],
    ],
    'operations' => [
        'authentification',
        'inscription',
        'initiation de paiement',
        'paiement',
        'callback',
        'gestion des erreurs',
        'erreur',
        'remboursement',
        'renouvellement de secret',
        "méthode d'intégration",
    ],
    'prompt_version' => '2026-08-13.1-pvit-canaux',
];
