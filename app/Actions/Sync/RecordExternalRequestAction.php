<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Models\ExternalRequest;
use Illuminate\Support\Str;

class RecordExternalRequestAction
{
    public function execute(array $payload): ExternalRequest
    {
        $attributes = [
            'id' => (string) Str::ulid(),
            'run_id' => $payload['run_id'] ?? null,
            'driver' => $payload['driver'],
            'method' => strtoupper((string) $payload['method']),
            'url' => (string) $payload['url'],
            'query_json' => $payload['query'] ?? null,
            'req_headers' => $payload['req_headers'] ?? null,
            'req_body' => $payload['req_body'] ?? null,
            'resp_status' => $payload['resp_status'] ?? null,
            'resp_headers' => $payload['resp_headers'] ?? null,
            'resp_body' => $payload['resp_body'] ?? null,
            'duration_ms' => $payload['duration_ms'] ?? null,
            'attempt' => $payload['attempt'] ?? 1,
            'outcome' => $payload['outcome'],
            'idempotency_key' => $payload['idempotency_key'] ?? null,
        ];

        return ExternalRequest::query()->create($attributes);
    }
}
