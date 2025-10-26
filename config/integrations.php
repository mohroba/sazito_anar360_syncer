<?php

declare(strict_types=1);

return [
    'anar360' => [
        'enabled' => env('INTEGRATIONS_ANAR360_ENABLED', true),
        'base_uri' => env('ANAR360_BASE', 'https://api.anar360.com/api/360/'),
        'token' => env('ANAR360_TOKEN', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6IjY4MDI0N2Y1N2U0ZDIyZGJhNmRkMGNjNiIsImFjY291bnQiOiI2ODAyNDdmNTdlNGQyMmRiYTZkZDBjYzgiLCJyb2xlcyI6W10sImlhdCI6MTc0NTUyOTY2NX0.lJxeJBq7wWBB7qUW7t-ozrNPj7WaOcajxTKcJVJCZEI'),
        'page_limit' => (int) env('SYNC_PAGE_LIMIT', 25),
        'since_ms' => (int) env('SYNC_SINCE_MS', -120000),
    ],
    'sazito' => [
        'enabled' => env('INTEGRATIONS_SAZITO_ENABLED', true),
        'base_uri' => env('SAZITO_BASE', 'https://nadinmed.ir/api/v1'),
        'api_key' => env('SAZITO_KEY', 'y%s&2a6n4k'),
        'rate_limit_per_minute' => (int) env('RATE_LIMIT_PER_MIN', 240),
        'page_size' => (int) env('SAZITO_PAGE_SIZE', 100),
    ],
    'http' => [
        'timeout' => (int) env('HTTP_TIMEOUT', 15),
        'retries' => (int) env('HTTP_RETRIES', 3),
        'retry_backoff_ms' => (int) env('HTTP_RETRY_BACKOFF_MS', 500),
    ],
];
