<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Sync\FetchProductsAction;
use App\Jobs\UpdateVariantPriceJob;
use App\Jobs\UpdateVariantStockJob;
use App\Models\SyncCursor;
use App\Models\SyncRun;
use Domain\DTO\ProductDTO;
use Domain\DTO\VariantDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
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

    public function test_command_creates_run_and_dispatches_jobs(): void
    {
        Bus::fake();

        $mock = Mockery::mock(FetchProductsAction::class);
        $mock->shouldReceive('execute')->once()->andReturn([
            'products' => [
                new ProductDTO('product-1', 'Demo Product', [
                    new VariantDTO('variant-1', 1000, 5),
                ]),
            ],
            'meta' => [
                'page' => 1,
                'pages_total' => 1,
            ],
        ]);

        $this->app->instance(FetchProductsAction::class, $mock);

        Artisan::call('sync:products', [
            '--since-ms' => -1000,
            '--page' => 1,
            '--limit' => 25,
        ]);

        $this->assertDatabaseCount('sync_runs', 1);
        $run = SyncRun::query()->first();
        $this->assertSame('success', $run->status);

        Bus::assertDispatched(UpdateVariantPriceJob::class);
        Bus::assertDispatched(UpdateVariantStockJob::class);

        $pageCursor = SyncCursor::query()->where('key', 'products.page')->first();
        $sinceCursor = SyncCursor::query()->where('key', 'products.since')->first();
        $this->assertNotNull($pageCursor);
        $this->assertNotNull($sinceCursor);
        $this->assertSame(1, $pageCursor->value_json['page']);
        $this->assertSame(-1000, $sinceCursor->value_json['since_ms']);
    }
}
