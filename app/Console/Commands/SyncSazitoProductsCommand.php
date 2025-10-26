<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Sync\FetchSazitoProductsAction;
use App\Actions\Sync\RecordEventAction;
use App\Actions\Sync\UpsertCursorAction;
use App\Actions\Sync\UpsertSazitoCatalogueAction;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class SyncSazitoProductsCommand extends Command
{
    protected $signature = 'sync:sazito-products {--page=1} {--limit=} {--all=false}';

    protected $description = 'Fetch products from Sazito and persist catalogue mappings.';

    public function __construct(
        private readonly FetchSazitoProductsAction $fetchProducts,
        private readonly UpsertSazitoCatalogueAction $upsertCatalogue,
        private readonly RecordEventAction $recordEvent,
        private readonly UpsertCursorAction $upsertCursor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('integrations.sazito.enabled', true)) {
            $this->warn('Sazito integration disabled.');

            return self::SUCCESS;
        }

        $page = (int) $this->option('page');
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : 0;
        if ($limit <= 0) {
            $limit = (int) config('integrations.sazito.page_size', 100);
        }
        $fetchAll = filter_var($this->option('all'), FILTER_VALIDATE_BOOLEAN);

        $run = SyncRun::query()->create([
            'id' => (string) Str::ulid(),
            'started_at' => now(),
            'status' => 'running',
            'scope' => 'sazito-products',
            'page' => $page,
        ]);

        $totals = [
            'products' => 0,
            'variants' => 0,
            'mappings' => 0,
        ];

        try {
            do {
                $result = $this->fetchProducts->execute($run, $page, $limit);
                $totals['products'] += count($result['products']);

                $upserted = $this->upsertCatalogue->execute($result['products']);
                $totals['variants'] += $upserted['variants_upserted'];
                $totals['mappings'] += $upserted['mappings_attached'];

                $this->recordEvent->execute($run->id, 'SAZITO_CATALOGUE_UPSERTED', [
                    'page' => $page,
                    'products' => $upserted['products_upserted'],
                    'variants' => $upserted['variants_upserted'],
                    'mappings' => $upserted['mappings_attached'],
                ]);

                $meta = $result['meta'];

                $hasMore = false;
                if ($fetchAll) {
                    if (Arr::get($meta, 'has_more') === true) {
                        $hasMore = true;
                    } elseif (($meta['page'] ?? $page) < ($meta['pages_total'] ?? $page)) {
                        $hasMore = true;
                    } elseif (count($result['products']) >= $limit && $limit > 0) {
                        $hasMore = true;
                    }
                }

                if ($hasMore) {
                    $page = (int) ($meta['next_page'] ?? (($meta['page'] ?? $page) + 1));
                }
            } while ($fetchAll && $hasMore);

            $this->upsertCursor->execute('sazito.products.page', [
                'page' => $page,
            ]);

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'page' => $page,
                'totals_json' => $totals,
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

        $this->info(sprintf(
            'Sazito sync completed. Products: %d, new variants: %d, mappings: %d',
            $totals['products'],
            $totals['variants'],
            $totals['mappings'],
        ));

        return self::SUCCESS;
    }
}
