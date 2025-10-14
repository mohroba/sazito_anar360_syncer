<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationEvent extends Model
{
    protected $table = 'integration_events';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'id',
        'run_id',
        'type',
        'ref_id',
        'payload',
        'level',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'run_id');
    }
}
