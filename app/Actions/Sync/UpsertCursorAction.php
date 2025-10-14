<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Models\SyncCursor;

class UpsertCursorAction
{
    public function execute(string $key, array $value): SyncCursor
    {
        return SyncCursor::query()->updateOrCreate(
            ['key' => $key],
            [
                'value_json' => $value,
            ],
        );
    }
}
