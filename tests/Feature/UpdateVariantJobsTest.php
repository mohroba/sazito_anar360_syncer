<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Sync\RecordEventAction;
use App\Actions\Sync\UpdateVariantPriceAction;
use App\Actions\Sync\UpdateVariantStockAction;
use App\Jobs\UpdateVariantPriceJob;
use App\Jobs\UpdateVariantStockJob;
use App\Models\Failure;
use App\Support\CircuitBreaker;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

class UpdateVariantJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_price_job_persists_failure_on_client_error(): void
    {
        config(['cache.default' => 'array', 'integrations.sazito.rate_limit_per_minute' => 1000]);
        Carbon::setTestNow(Carbon::now());

        $job = new UpdateVariantPriceJob('variant-price', 1000, 'run-1');

        $action = Mockery::mock(UpdateVariantPriceAction::class);
        $exception = new SazitoRequestException(422, ['error' => 'bad'], 'Client error');
        $action->shouldReceive('execute')->once()->andThrow($exception);

        $recordEvent = Mockery::mock(RecordEventAction::class);
        $recordEvent->shouldReceive('execute')
            ->once()
            ->with('run-1', 'VALIDATION_FAILED', Mockery::on(function (array $payload): bool {
                return $payload['variant_id'] === 'variant-price'
                    && $payload['status'] === 422;
            }), 'variant-price', 'error');

        $circuitBreaker = $this->app->make(CircuitBreaker::class);

        $rateKey = sprintf('sazito-rate:%s', now()->format('YmdHi'));
        RateLimiter::clear($rateKey);

        $job->handle($action, $recordEvent, $circuitBreaker);

        $failure = Failure::query()->where('context', 'SAZITO_UPDATE_PRICE')->first();
        $this->assertNotNull($failure);
        $this->assertSame('variant-price', $failure->ref_id);
        $this->assertSame('Client error', $failure->last_error);
        $this->assertSame(1, $failure->attempts);
        $this->assertSame(422, $failure->payload['status']);
    }

    public function test_stock_job_persists_failure_on_client_error(): void
    {
        config(['cache.default' => 'array', 'integrations.sazito.rate_limit_per_minute' => 1000]);
        Carbon::setTestNow(Carbon::now());

        $job = new UpdateVariantStockJob('variant-stock', 5, false, 'run-2');

        $action = Mockery::mock(UpdateVariantStockAction::class);
        $exception = new SazitoRequestException(422, ['error' => 'bad'], 'Stock error');
        $action->shouldReceive('execute')->once()->andThrow($exception);

        $recordEvent = Mockery::mock(RecordEventAction::class);
        $recordEvent->shouldReceive('execute')
            ->once()
            ->with('run-2', 'VALIDATION_FAILED', Mockery::on(function (array $payload): bool {
                return $payload['variant_id'] === 'variant-stock'
                    && $payload['status'] === 422;
            }), 'variant-stock', 'error');

        $circuitBreaker = $this->app->make(CircuitBreaker::class);

        $rateKey = sprintf('sazito-rate:%s', now()->format('YmdHi'));
        RateLimiter::clear($rateKey);

        $job->handle($action, $recordEvent, $circuitBreaker);

        $failure = Failure::query()->where('context', 'SAZITO_UPDATE_STOCK')->first();
        $this->assertNotNull($failure);
        $this->assertSame('variant-stock', $failure->ref_id);
        $this->assertSame('Stock error', $failure->last_error);
        $this->assertSame(1, $failure->attempts);
        $this->assertSame(422, $failure->payload['status']);
        $this->assertFalse((bool) $failure->payload['is_relative']);
    }
}
