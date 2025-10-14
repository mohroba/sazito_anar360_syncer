<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\UpdateVariantPriceJob;
use App\Jobs\UpdateVariantStockJob;
use App\Models\Failure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;

class RetryFailuresCommand extends Command
{
    protected $signature = 'sync:retry-failures';

    protected $description = 'Retry failed synchronization payloads';

    private const MAX_ATTEMPTS = 10;

    public function __construct(private readonly Dispatcher $dispatcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $failures = Failure::query()
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('next_retry_at')
            ->limit(100)
            ->get();

        if ($failures->isEmpty()) {
            $this->info('No failures ready for retry.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $payload = $failure->payload ?? [];
            switch ($failure->context) {
                case 'SAZITO_UPDATE_PRICE':
                    $this->dispatcher->dispatch(new UpdateVariantPriceJob(
                        $payload['variant_id'],
                        $payload['price'],
                        $payload['run_id'] ?? (string) $failure->id,
                        $payload['discount_price'] ?? null,
                        array_key_exists('has_raw_price', $payload) ? $payload['has_raw_price'] : null,
                    ));
                    break;
                case 'SAZITO_UPDATE_STOCK':
                    $this->dispatcher->dispatch(new UpdateVariantStockJob(
                        $payload['variant_id'],
                        $payload['stock'],
                        (bool) ($payload['is_relative'] ?? false),
                        $payload['run_id'] ?? (string) $failure->id,
                    ));
                    break;
                default:
                    continue 2;
            }

            $failure->update([
                'next_retry_at' => now()->addMinutes(5),
                'attempts' => $failure->attempts + 1,
            ]);
        }

        $this->info(sprintf('Retried %d failures', $failures->count()));

        return self::SUCCESS;
    }
}
