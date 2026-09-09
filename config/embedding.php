<?php

$fallbackProviders = env('EMBEDDING_FALLBACK_PROVIDERS', 'nvidia');

return [
    'default_provider' => env('EMBEDDING_DEFAULT_PROVIDER', 'nvidia'),
    'fallback_providers' => array_values(array_filter(array_map('trim', explode(',', $fallbackProviders)))),
    'allow_provider_fallback' => (bool) env('EMBEDDING_ALLOW_PROVIDER_FALLBACK', false),
    'dimensions' => (int) env('EMBEDDING_DIMENSIONS', env('RAG_EMBEDDING_DIMENSIONS', 768)),
    'timeout' => env('EMBEDDING_TIMEOUT', 45),
    'retry_attempts' => (int) env('EMBEDDING_RETRY_ATTEMPTS', 2),
    'retry_delay_ms' => (int) env('EMBEDDING_RETRY_DELAY_MS', 1000),

    'providers' => [
        'nvidia' => [
            'label' => 'NVIDIA NeMo Retriever Embedding NIM',
            'base_url' => env('NVIDIA_EMBEDDING_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
            'api_key' => env('NVIDIA_EMBEDDING_API_KEY') ?: env('NVIDIA_API_KEY'),
            'model' => env('NVIDIA_EMBEDDING_MODEL', 'nvidia/llama-nemotron-embed-vl-1b-v2'),
            'timeout' => env('NVIDIA_EMBEDDING_TIMEOUT', env('EMBEDDING_TIMEOUT', 45)),
            'truncate' => env('NVIDIA_EMBEDDING_TRUNCATE', 'END'),
        ],

        'gemini' => [
            'label' => 'Gemini Embedding',
            'base_url' => env('GEMINI_EMBEDDING_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'api_key' => env('GEMINI_EMBEDDING_API_KEY') ?: env('GEMINI_API_KEY'),
            'model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-2'),
            'timeout' => env('GEMINI_EMBEDDING_TIMEOUT', env('EMBEDDING_TIMEOUT', 45)),
        ],

        'local' => [
            'label' => 'Local hash embedding',
            'model' => env('LOCAL_EMBEDDING_MODEL', 'local-hash-v1'),
        ],
    ],
];
