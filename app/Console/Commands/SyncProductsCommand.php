<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Sync\FetchProductsAction;
use App\Actions\Sync\RecordEventAction;
use App\Actions\Sync\UpsertCursorAction;
use App\Jobs\UpdateVariantPriceJob;
use App\Jobs\UpdateVariantStockJob;
use App\Models\SazitoProduct;
use App\Models\SazitoVariant;
use App\Models\SyncRun;
use App\Support\TitleNormalizer;
use Domain\DTO\ProductDTO;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use Throwable;

class SyncProductsCommand extends Command
{
    protected $signature = 'sync:products {--since-ms=} {--page=1} {--limit=} {--run-scope=incremental}';

    protected $description = 'Sync products from Anar360 and update Sazito';

    public function __construct(
        private readonly FetchProductsAction $fetchProducts,
        private readonly RecordEventAction $recordEvent,
        private readonly UpsertCursorAction $upsertCursor,
        private readonly Dispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sinceMs = (int) ($this->option('since-ms') ?? config('integrations.anar360.since_ms'));
        $page = (int) $this->option('page');
        $limit = (int) ($this->option('limit') ?? config('integrations.anar360.page_limit'));
        $scope = (string) $this->option('run-scope');

        if (! config('integrations.anar360.enabled', true)) {
            $this->warn('Anar360 integration disabled.');

            return self::SUCCESS;
        }

        $run = SyncRun::query()->create([
            'id' => (string) Str::ulid(),
            'started_at' => now(),
            'status' => 'running',
            'scope' => $scope,
            'since_ms' => $sinceMs,
            'page' => $page,
        ]);

        try {
            $result = $this->fetchProducts->execute($run, $page, $limit, $sinceMs);
            $meta = $result['meta'];

            $jobsDispatched = 0;
            foreach ($result['products'] as $product) {
                $sazitoProduct = $this->ensureSazitoProductMapping($product, $run);

                foreach ($product->variants as $variant) {
                    $mapping = SazitoVariant::query()->where('anar360_variant_id', $variant->id)->first();

                    if ($mapping === null) {
                        $this->recordEvent->execute($run->id, 'SKIPPED', [
                            'reason' => 'mapping-missing',
                            'variant_id' => $variant->id,
                            'product_id' => $product->id,
                        ], $variant->id, 'warning');

                        continue;
                    }

                    $this->dispatcher->dispatch(new UpdateVariantPriceJob(
                        $mapping->sazito_id,
                        $variant->price,
                        $run->id,
                        sourceVariantId: $variant->id,
                    ));
                    $this->dispatcher->dispatch(new UpdateVariantStockJob(
                        $mapping->sazito_id,
                        $variant->stock,
                        false,
                        $run->id,
                        sourceVariantId: $variant->id,
                    ));
                    $jobsDispatched += 2;
                }
            }

            $this->recordEvent->execute($run->id, 'PRODUCTS_FETCHED', [
                'jobs' => $jobsDispatched,
            ]);

            $this->upsertCursor->execute('products.page', [
                'page' => $page,
            ]);

            $this->upsertCursor->execute('products.since', [
                'since_ms' => $sinceMs,
            ]);

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'page' => $meta['page'] ?? $page,
                'pages_total' => $meta['pages_total'] ?? null,
                'totals_json' => $meta,
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Sync completed');

        return self::SUCCESS;
    }

    private function ensureSazitoProductMapping(ProductDTO $product, SyncRun $run): ?SazitoProduct
    {
        $existing = SazitoProduct::query()->where('anar360_product_id', $product->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $normalizedTitle = TitleNormalizer::normalize($product->title);
        if ($normalizedTitle === null) {
            return null;
        }

        $candidate = SazitoProduct::query()
            ->where('title_normalized', $normalizedTitle)
            ->orderByDesc('synced_at')
            ->first();

        if ($candidate === null) {
            return null;
        }

        if ($candidate->anar360_product_id !== $product->id) {
            $candidate->anar360_product_id = $product->id;
            $candidate->save();
        }

        return $candidate;
    }
}
