<?php

$fallbackProviders = env('LLM_FALLBACK_PROVIDERS', 'ngrok');

return [
    'default_provider' => env('LLM_DEFAULT_PROVIDER', 'ngrok'),
    'fallback_providers' => array_values(array_filter(array_map('trim', explode(',', $fallbackProviders)))),
    'temperature' => env('LLM_TEMPERATURE', 0.1),
    'timeout' => env('LLM_TIMEOUT', 45),
    'retry_attempts' => env('LLM_RETRY_ATTEMPTS', 2),
    'retry_delay_ms' => env('LLM_RETRY_DELAY_MS', 1000),

    'providers' => [
        'ngrok' => [
            'label' => 'Ngrok OpenAI-compatible tunnel',
            'base_url' => env('NGROK_LLM_BASE_URL', ''),
            'endpoint' => env('NGROK_LLM_ENDPOINT', 'chat/completions'),
            'api_key' => env('NGROK_LLM_API_KEY'),
            'model' => env('NGROK_LLM_MODEL', ''),
            'timeout' => env('NGROK_LLM_TIMEOUT', env('LLM_TIMEOUT', 45)),
            'connect_timeout' => env('NGROK_LLM_CONNECT_TIMEOUT', 10),
            'retry_attempts' => env('NGROK_LLM_RETRY_ATTEMPTS', env('LLM_RETRY_ATTEMPTS', 2)),
            'retry_delay_ms' => env('NGROK_LLM_RETRY_DELAY_MS', env('LLM_RETRY_DELAY_MS', 1000)),
            'auth_header' => env('NGROK_LLM_AUTH_HEADER', 'Authorization'),
            'auth_scheme' => env('NGROK_LLM_AUTH_SCHEME', 'Bearer'),
            'headers_json' => env('NGROK_LLM_HEADERS_JSON', '{}'),
            'tunnel' => true,
        ],

        'gemini' => [
            'label' => 'Google Gemini Flash',
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', ''),
            'timeout' => env('GEMINI_TIMEOUT', env('LLM_TIMEOUT', 45)),
        ],

        'groq' => [
            'label' => 'Groq',
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'api_key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', ''),
            'timeout' => env('GROQ_TIMEOUT', env('LLM_TIMEOUT', 45)),
        ],

        'deepseek' => [
            'label' => 'DeepSeek (via NVIDIA NIM)',
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_MODEL', ''),
            'timeout' => env('DEEPSEEK_TIMEOUT', env('LLM_TIMEOUT', 45)),
        ],

        'mistral' => [
            'label' => 'Mistral AI',
            'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
            'api_key' => env('MISTRAL_API_KEY'),
            'model' => env('MISTRAL_MODEL', ''),
            'timeout' => env('MISTRAL_TIMEOUT', env('LLM_TIMEOUT', 45)),
        ],
    ],
];
