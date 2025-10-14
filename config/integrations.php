<?php

declare(strict_types=1);

return [
    'anar360' => [
        'enabled' => env('INTEGRATIONS_ANAR360_ENABLED', true),
        'base_uri' => env('ANAR360_BASE', ''),
        'token' => env('ANAR360_TOKEN', ''),
        'page_limit' => (int) env('SYNC_PAGE_LIMIT', 25),
        'since_ms' => (int) env('SYNC_SINCE_MS', -120000),
    ],
    'sazito' => [
        'enabled' => env('INTEGRATIONS_SAZITO_ENABLED', true),
        'base_uri' => env('SAZITO_BASE', ''),
        'api_key' => env('SAZITO_KEY', ''),
        'rate_limit_per_minute' => (int) env('RATE_LIMIT_PER_MIN', 240),
    ],
    'http' => [
        'timeout' => (int) env('HTTP_TIMEOUT', 15),
        'retries' => (int) env('HTTP_RETRIES', 3),
        'retry_backoff_ms' => (int) env('HTTP_RETRY_BACKOFF_MS', 500),
    ],
];
