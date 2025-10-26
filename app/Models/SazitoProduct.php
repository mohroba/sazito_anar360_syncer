<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $sazito_id
 */
class SazitoProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'sazito_id',
        'title',
        'title_normalized',
        'slug',
        'anar360_product_id',
        'raw_payload',
        'synced_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(SazitoVariant::class);
    }
}
