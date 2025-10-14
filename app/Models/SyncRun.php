<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    use HasFactory;

    protected $table = 'sync_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'started_at',
        'finished_at',
        'status',
        'scope',
        'since_ms',
        'page',
        'pages_total',
        'totals_json',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'totals_json' => 'array',
    ];

    public function externalRequests(): HasMany
    {
        return $this->hasMany(ExternalRequest::class, 'run_id');
    }

    public function integrationEvents(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class, 'run_id');
    }
}
