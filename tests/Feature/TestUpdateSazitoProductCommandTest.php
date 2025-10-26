<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Sync\UpdateVariantPriceAction;
use App\Actions\Sync\UpdateVariantStockAction;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class TestUpdateSazitoProductCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_requires_price_or_stock(): void
    {
        $this->app->instance(UpdateVariantPriceAction::class, Mockery::mock(UpdateVariantPriceAction::class));
        $this->app->instance(UpdateVariantStockAction::class, Mockery::mock(UpdateVariantStockAction::class));

        $exitCode = Artisan::call('integration:test-update-product', [
            'variant' => 'variant-1',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('You must provide at least one of --price or --stock.', Artisan::output());
    }

    public function test_command_updates_price_and_stock(): void
    {
        $capturedRunId = null;

        $priceAction = Mockery::mock(UpdateVariantPriceAction::class);
        $priceAction->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(function (string $runId) use (&$capturedRunId): bool {
                $this->assertNotEmpty($runId);
                $capturedRunId = $runId;

                return true;
            }), 'variant-1', 1200, 1000, true)
            ->andReturn([]);
        $this->app->instance(UpdateVariantPriceAction::class, $priceAction);

        $stockAction = Mockery::mock(UpdateVariantStockAction::class);
        $stockAction->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(function (string $runId) use (&$capturedRunId): bool {
                return $runId === $capturedRunId;
            }), 'variant-1', 5, true)
            ->andReturn([]);
        $this->app->instance(UpdateVariantStockAction::class, $stockAction);

        $exitCode = Artisan::call('integration:test-update-product', [
            'variant' => 'variant-1',
            '--price' => 1200,
            '--discount' => 1000,
            '--has-raw-price' => 'true',
            '--stock' => 5,
            '--relative' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Price update request sent for variant-1.', $output);
        $this->assertStringContainsString('Stock update request sent for variant-1.', $output);
    }

    public function test_command_handles_sazito_exceptions(): void
    {
        $priceAction = Mockery::mock(UpdateVariantPriceAction::class);
        $priceAction->shouldReceive('execute')
            ->once()
            ->andThrow(new SazitoRequestException(422, ['error' => 'bad'], 'failure'));
        $this->app->instance(UpdateVariantPriceAction::class, $priceAction);

        $stockAction = Mockery::mock(UpdateVariantStockAction::class);
        $this->app->instance(UpdateVariantStockAction::class, $stockAction);

        $exitCode = Artisan::call('integration:test-update-product', [
            'variant' => 'variant-1',
            '--price' => 1200,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Sazito rejected the price update', Artisan::output());
    }
}
