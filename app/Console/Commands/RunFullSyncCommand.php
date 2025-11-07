<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;

class RunFullSyncCommand extends Command
{
    protected $signature = 'sync:test-run
        {--since-ms= : Override the incremental cursor (milliseconds).}
        {--page=1 : Page to start from when fetching Anar360 products.}
        {--limit= : Page size when fetching Anar360 products.}
        {--scope=manual : Scope label recorded on the SyncRun.}
        {--catalogue-page=1 : Starting page when syncing the Sazito catalogue.}
        {--catalogue-limit= : Page size when syncing the Sazito catalogue.}
        {--catalogue-all=true : Whether to iterate through all catalogue pages.}
    ';

    protected $description = 'Trigger a full synchronisation run including Sazito catalogue, products and orders.';

    public function handle(): int
    {
        $catalogueOptions = $this->catalogueOptions();
        $productOptions = $this->productOptions();

        $this->info('Running Sazito catalogue synchronisation...');
        $catalogueExit = Artisan::call('sync:sazito-products', $catalogueOptions);
        $catalogueOutput = trim((string) Artisan::output());

        if ($catalogueOutput !== '') {
            $this->line($catalogueOutput);
        }

        if ($catalogueExit !== Command::SUCCESS) {
            $this->error('Sazito catalogue synchronisation failed. Aborting full sync.');

            return Command::FAILURE;
        }

        $this->info('Running Anar360 product & order synchronisation...');
        $productsExit = Artisan::call('sync:products', $productOptions);
        $productsOutput = trim((string) Artisan::output());

        if ($productsOutput !== '') {
            $this->line($productsOutput);
        }

        if ($productsExit !== Command::SUCCESS) {
            $this->error('Anar360 synchronisation failed.');

            return Command::FAILURE;
        }

        $this->info('Full sync completed successfully.');

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int|string>
     */
    private function productOptions(): array
    {
        $sinceMsOption = $this->option('since-ms');
        $sinceMs = is_numeric($sinceMsOption)
            ? (int) $sinceMsOption
            : (int) config('integrations.anar360.since_ms', 0);

        $page = (int) $this->option('page');
        if ($page <= 0) {
            $page = 1;
        }

        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;
        if ($limit === null || $limit <= 0) {
            $limit = (int) config('integrations.anar360.page_limit', 50);
        }

        $scope = (string) ($this->option('scope') ?? 'manual');
        if ($scope === '') {
            $scope = 'manual';
        }

        return [
            '--since-ms' => $sinceMs,
            '--page' => $page,
            '--limit' => $limit,
            '--run-scope' => $scope,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function catalogueOptions(): array
    {
        $page = (int) $this->option('catalogue-page');
        if ($page <= 0) {
            $page = 1;
        }

        $limitOption = $this->option('catalogue-limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;
        if ($limit === null || $limit <= 0) {
            $limit = (int) config('integrations.sazito.page_size', 100);
        }

        $fetchAll = filter_var($this->option('catalogue-all'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $allFlag = $fetchAll !== false;

        return Arr::whereNotNull([
            '--page' => $page,
            '--limit' => $limit,
            '--all' => $allFlag ? 'true' : 'false',
        ]);
    }
}
