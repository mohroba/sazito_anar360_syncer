<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Sync\FetchProductsAction;
use App\Console\Commands\SyncProductsCommand;
use App\Jobs\UpdateVariantPriceJob;
use App\Jobs\UpdateVariantStockJob;
use App\Models\IntegrationEvent;
use App\Models\SazitoProduct;
use App\Support\TitleNormalizer;
use Domain\DTO\ProductDTO;
use Domain\DTO\VariantDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class SyncProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_dispatches_jobs_with_sazito_mapping(): void
    {
        Bus::fake();

        $product = SazitoProduct::query()->create([
            'sazito_id' => 'sazito-product-1',
        ]);

        $product->variants()->create([
            'sazito_id' => 'sazito-variant-1',
            'anar360_variant_id' => 'anar-variant-1',
        ]);

        $fetchAction = Mockery::mock(FetchProductsAction::class);
        $fetchAction->shouldReceive('execute')
            ->once()
            ->andReturn([
                'products' => [
                    new ProductDTO('anar-product-1', 'Product', [
                        new VariantDTO('anar-variant-1', 1500, 3),
                    ]),
                ],
                'meta' => ['page' => 1],
            ]);

        $this->app->instance(FetchProductsAction::class, $fetchAction);

        $exitCode = Artisan::call(SyncProductsCommand::class, [
            '--since-ms' => 0,
            '--page' => 1,
            '--limit' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        Bus::assertDispatched(UpdateVariantPriceJob::class, function (UpdateVariantPriceJob $job): bool {
            return $this->readProperty($job, 'variantId') === 'sazito-variant-1'
                && $this->readProperty($job, 'sourceVariantId') === 'anar-variant-1';
        });

        Bus::assertDispatched(UpdateVariantStockJob::class, function (UpdateVariantStockJob $job): bool {
            return $this->readProperty($job, 'variantId') === 'sazito-variant-1'
                && $this->readProperty($job, 'sourceVariantId') === 'anar-variant-1';
        });

        $this->assertDatabaseMissing('integration_events', ['type' => 'SKIPPED', 'payload->reason' => 'mapping-missing']);
    }

    public function test_records_event_when_mapping_missing(): void
    {
        Bus::fake();

        $fetchAction = Mockery::mock(FetchProductsAction::class);
        $fetchAction->shouldReceive('execute')
            ->once()
            ->andReturn([
                'products' => [
                    new ProductDTO('anar-product-2', 'Product', [
                        new VariantDTO('anar-missing', 1500, 3),
                    ]),
                ],
                'meta' => ['page' => 1],
            ]);

        $this->app->instance(FetchProductsAction::class, $fetchAction);

        $exitCode = Artisan::call(SyncProductsCommand::class, [
            '--since-ms' => 0,
            '--page' => 1,
            '--limit' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        Bus::assertNothingDispatched();

        $event = IntegrationEvent::query()->where('type', 'SKIPPED')->where('payload->reason', 'mapping-missing')->first();
        $this->assertNotNull($event);
        $this->assertSame('anar-missing', $event->payload['variant_id']);
    }

    public function test_links_sazito_product_by_title_when_mapping_missing(): void
    {
        Bus::fake();

        $title = 'کاندوم 12 عددي ارگاسميک کاپوت';

        $product = SazitoProduct::query()->create([
            'sazito_id' => 'sazito-product-3',
            'title' => $title,
            'title_normalized' => TitleNormalizer::normalize($title),
        ]);

        $fetchAction = Mockery::mock(FetchProductsAction::class);
        $fetchAction->shouldReceive('execute')
            ->once()
            ->andReturn([
                'products' => [
                    new ProductDTO('anar-product-3', $title, [
                        new VariantDTO('anar-missing-2', 2100, 5),
                    ]),
                ],
                'meta' => ['page' => 1],
            ]);

        $this->app->instance(FetchProductsAction::class, $fetchAction);

        Artisan::call(SyncProductsCommand::class, [
            '--since-ms' => 0,
            '--page' => 1,
            '--limit' => 1,
        ]);

        $product->refresh();
        $this->assertSame('anar-product-3', $product->anar360_product_id);

    }

    private function readProperty(object $object, string $property): mixed
    {
        $reader = function () use ($property) {
            return $this->{$property};
        };

        return $reader->call($object);
    }
}
