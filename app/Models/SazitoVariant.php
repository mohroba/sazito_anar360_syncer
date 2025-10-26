<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $sazito_id
 * @property string|null $anar360_variant_id
 */
class SazitoVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'sazito_product_id',
        'sazito_id',
        'title',
        'sku',
        'anar360_variant_id',
        'external_references',
        'raw_payload',
        'synced_at',
    ];

    protected $casts = [
        'external_references' => 'array',
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(SazitoProduct::class, 'sazito_product_id');
    }
}
