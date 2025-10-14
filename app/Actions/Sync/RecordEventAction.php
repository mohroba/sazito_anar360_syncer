<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Models\IntegrationEvent;
use Illuminate\Support\Str;

class RecordEventAction
{
    public function execute(string $runId, string $type, array $payload = [], ?string $refId = null, string $level = 'info'): IntegrationEvent
    {
        return IntegrationEvent::query()->create([
            'id' => (string) Str::ulid(),
            'run_id' => $runId,
            'type' => $type,
            'ref_id' => $refId,
            'payload' => $payload,
            'level' => $level,
        ]);
    }
}
