<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Sync\BackoffPolicy;
use App\Actions\Sync\RecordEventAction;
use App\Actions\Sync\UpdateVariantStockAction;
use App\Models\ExternalRequest;
use App\Models\Failure;
use App\Support\CircuitBreaker;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class UpdateVariantStockJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    private string $idempotencyKey;

    public function __construct(
        private readonly string $variantId,
        private readonly int $stock,
        private readonly bool $isRelative,
        private readonly string $runId,
        private readonly ?string $sourceVariantId = null,
    ) {
        $this->idempotencyKey = hash('sha256', sprintf('SAZITO:stock:%s:%d:%d', $variantId, $stock, $isRelative ? 1 : 0));
    }

    public function backoff(): array
    {
        $policy = app(BackoffPolicy::class);

        return [
            $policy->calculate(1) / 1000,
            $policy->calculate(2) / 1000,
            $policy->calculate(3) / 1000,
            $policy->calculate(4) / 1000,
            $policy->calculate(5) / 1000,
        ];
    }

    public function handle(
        UpdateVariantStockAction $action,
        RecordEventAction $recordEvent,
        CircuitBreaker $circuitBreaker,
    ): void {
        if (! config('integrations.sazito.enabled', true)) {
            $recordEvent->execute($this->runId, 'SKIPPED', [
                'reason' => 'sazito-disabled',
                'variant_id' => $this->variantId,
                ...$this->sourceVariantContext(),
            ], $this->variantId, 'warning');

            return;
        }

        if (ExternalRequest::query()->where('driver', 'SAZITO')->where('idempotency_key', $this->idempotencyKey)->where('outcome', 'success')->exists()) {
            $recordEvent->execute($this->runId, 'SKIPPED', [
                'reason' => 'idempotent-hit',
                'variant_id' => $this->variantId,
                ...$this->sourceVariantContext(),
            ], $this->variantId, 'info');

            return;
        }

        $circuitKey = 'sazito';
        if ($circuitBreaker->isOpen($circuitKey)) {
            $recordEvent->execute($this->runId, 'SKIPPED', [
                'reason' => 'circuit-open',
                'variant_id' => $this->variantId,
                ...$this->sourceVariantContext(),
            ], $this->variantId, 'warning');
            $this->release(30);

            return;
        }

        $rateLimit = config('integrations.sazito.rate_limit_per_minute');
        $rateKey = sprintf('sazito-rate:%s', now()->format('YmdHi'));
        if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
            $recordEvent->execute($this->runId, 'RATE_LIMITED', [
                'variant_id' => $this->variantId,
                ...$this->sourceVariantContext(),
            ], $this->variantId, 'warning');
            $this->release(10);

            return;
        }

        RateLimiter::hit($rateKey, 60);

        try {
            $action->execute(
                runId: $this->runId,
                variantId: $this->variantId,
                stock: $this->stock,
                isRelative: $this->isRelative,
                sourceVariantId: $this->sourceVariantId,
                options: [
                    'idempotency_key' => $this->idempotencyKey,
                ],
            );
            $circuitBreaker->recordSuccess($circuitKey);
        } catch (SazitoRequestException $exception) {
            $circuitBreaker->recordFailure($circuitKey);

            if ($exception->isClientError() && $exception->statusCode() !== 409) {
                $this->persistFailure($exception->getMessage(), [
                    'status' => $exception->statusCode(),
                    'response' => $exception->responseBody(),
                ]);

                $recordEvent->execute($this->runId, 'VALIDATION_FAILED', [
                    'variant_id' => $this->variantId,
                    'stock' => $this->stock,
                    'is_relative' => $this->isRelative,
                    'status' => $exception->statusCode(),
                    ...$this->sourceVariantContext(),
                ], $this->variantId, 'error');

                return;
            }

            throw $exception;
        } catch (Throwable $exception) {
            $circuitBreaker->recordFailure($circuitKey);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->persistFailure($exception->getMessage());
    }

    /**
     * @param array<string, mixed> $extraPayload
     */
    private function persistFailure(string $message, array $extraPayload = []): void
    {
        $failure = Failure::query()->firstOrNew([
            'context' => 'SAZITO_UPDATE_STOCK',
            'ref_id' => $this->variantId,
        ]);

        $failure->payload = [
            'variant_id' => $this->variantId,
            'stock' => $this->stock,
            'is_relative' => $this->isRelative,
            'run_id' => $this->runId,
            ...$this->sourceVariantContext(),
            ...$extraPayload,
        ];
        $failure->last_error = $message;
        $failure->attempts = ($failure->attempts ?? 0) + 1;
        $failure->next_retry_at = now()->addMinutes(5);
        $failure->save();
    }

    /**
     * @return array<string, string>
     */
    private function sourceVariantContext(): array
    {
        return $this->sourceVariantId !== null ? ['source_variant_id' => $this->sourceVariantId] : [];
    }
}
