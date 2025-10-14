<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalRequest extends Model
{
    protected $table = 'external_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'id',
        'run_id',
        'driver',
        'method',
        'url',
        'query_json',
        'req_headers',
        'req_body',
        'resp_status',
        'resp_headers',
        'resp_body',
        'duration_ms',
        'attempt',
        'outcome',
        'idempotency_key',
    ];

    protected $casts = [
        'query_json' => 'array',
        'req_headers' => 'array',
        'req_body' => 'array',
        'resp_headers' => 'array',
        'resp_body' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'run_id');
    }
}
