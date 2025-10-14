<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SyncCursor extends Model
{
    protected $table = 'sync_cursors';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'key',
        'value_json',
    ];

    protected $casts = [
        'value_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $cursor): void {
            $cursor->id = $cursor->id ?: (string) Str::ulid();
        });
    }
}
