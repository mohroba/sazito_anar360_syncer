<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Sync\UpsertSazitoCatalogueAction;
use App\Models\SazitoProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsertSazitoCatalogueActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_attaches_anar360_product_mapping_from_references(): void
    {
        $action = new UpsertSazitoCatalogueAction();

        $result = $action->execute([
            [
                'id' => 'sazito-1',
                'title' => 'Example Product',
                'raw' => [
                    'external_id' => '5f4dcc3b5aa765d61d8327de',
                    'metadata' => [
                        'label' => 'Example',
                    ],
                ],
                'variants' => [],
            ],
        ]);

        $product = SazitoProduct::query()->where('sazito_id', 'sazito-1')->firstOrFail();

        $this->assertSame('5f4dcc3b5aa765d61d8327de', $product->anar360_product_id);
        $this->assertSame(1, $result['product_mappings_attached']);
    }

    public function test_does_not_override_existing_mapping_when_reference_missing(): void
    {
        SazitoProduct::query()->create([
            'sazito_id' => 'sazito-2',
            'title' => 'Existing',
            'anar360_product_id' => 'existing-id',
        ]);

        $action = new UpsertSazitoCatalogueAction();

        $result = $action->execute([
            [
                'id' => 'sazito-2',
                'title' => 'Existing',
                'raw' => ['metadata' => []],
                'variants' => [],
            ],
        ]);

        $product = SazitoProduct::query()->where('sazito_id', 'sazito-2')->firstOrFail();

        $this->assertSame('existing-id', $product->anar360_product_id);
        $this->assertSame(0, $result['product_mappings_attached']);
    }
}
