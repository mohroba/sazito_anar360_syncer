<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Sync\UpdateVariantPriceAction;
use App\Actions\Sync\UpdateVariantStockAction;
use App\Models\SyncRun;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class TestUpdateSazitoProductCommand extends Command
{
    protected $signature = 'integration:test-update-product
        {variant : Variant identifier in Sazito.}
        {--price= : Absolute price to send to Sazito.}
        {--discount= : Optional discount price.}
        {--has-raw-price= : Whether the variant has a raw price (true/false).}
        {--stock= : Absolute stock level to push to Sazito.}
        {--relative : Treat provided stock as a relative delta instead of an absolute value.}';

    protected $description = 'Manually updates a Sazito variant price and/or stock to verify connectivity.';

    public function __construct(
        private readonly UpdateVariantPriceAction $updateVariantPrice,
        private readonly UpdateVariantStockAction $updateVariantStock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $variantId         = (string) $this->argument('variant');
        $priceOption       = $this->option('price');
        $stockOption       = $this->option('stock');
        $discountOption    = $this->option('discount');
        $hasRawPriceOption = $this->option('has-raw-price');
        $isRelative        = (bool) $this->option('relative');

        if ($priceOption === null && $stockOption === null) {
            $this->error('You must provide at least one of --price or --stock.');

            return self::FAILURE;
        }

        /** ----------------------------------------------------------------
         *  1️⃣  Create a synthetic SyncRun row so FK constraints are happy
         * -----------------------------------------------------------------*/
        $runId = (string) Str::ulid();

        SyncRun::query()->create([
            'id'         => $runId,
            'started_at' => now(),
            'status'     => 'running',
            'scope'      => 'manual-test',
            'page'       => 0,
        ]);

        /** ----------------------------------------------------------------
         *  2️⃣  Perform price update (if requested)
         * -----------------------------------------------------------------*/
        if ($priceOption !== null) {
            $price         = (int) $priceOption;
            $discountPrice = $discountOption !== null ? (int) $discountOption : null;
            $hasRawPrice   = $this->normalizeBooleanOption($hasRawPriceOption);

            try {
                $this->updateVariantPrice->execute(
                    runId:         $runId,
                    variantId:     $variantId,
                    price:         $price,
                    discountPrice: $discountPrice,
                    hasRawPrice:   $hasRawPrice,
                );

                $this->info(sprintf('✅  Price update request sent for variant %s.', $variantId));
            } catch (SazitoRequestException $e) {
                $this->error(sprintf('❌  Sazito rejected the price update: %s', $e->getMessage()));
                $this->finalizeRun($runId, 'failed');

                return self::FAILURE;
            } catch (Throwable $e) {
                $this->error(sprintf('❌  Unexpected price update failure: %s', $e->getMessage()));
                $this->finalizeRun($runId, 'failed');

                return self::FAILURE;
            }
        }

        /** ----------------------------------------------------------------
         *  3️⃣  Perform stock update (if requested)
         * -----------------------------------------------------------------*/
        if ($stockOption !== null) {
            $stock = (int) $stockOption;

            try {
                $this->updateVariantStock->execute(
                    runId:        $runId,
                    variantId:    $variantId,
                    stock:        $stock,
                    isRelative:   $isRelative,
                );

                $this->info(sprintf('✅  Stock update request sent for variant %s.', $variantId));
            } catch (SazitoRequestException $e) {
                $this->error(sprintf('❌  Sazito rejected the stock update: %s', $e->getMessage()));
                $this->finalizeRun($runId, 'failed');

                return self::FAILURE;
            } catch (Throwable $e) {
                $this->error(sprintf('❌  Unexpected stock update failure: %s', $e->getMessage()));
                $this->finalizeRun($runId, 'failed');

                return self::FAILURE;
            }
        }

        /** ----------------------------------------------------------------
         *  4️⃣  Mark the synthetic run as finished-success
         * -----------------------------------------------------------------*/
        $this->finalizeRun($runId, 'success');

        return self::SUCCESS;
    }

    /**
     * Convert CLI boolean-ish strings into actual bool/null
     */
    private function normalizeBooleanOption(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower((string) $value)) {
            '1', 'true', 'yes', 'on'  => true,
            '0', 'false', 'no', 'off' => false,
            default                   => null,
        };
    }

    /**
     * Update the temporary SyncRun row’s final status.
     */
    private function finalizeRun(string $runId, string $status): void
    {
        SyncRun::query()->whereKey($runId)->update([
            'finished_at' => now(),
            'status'      => $status,
        ]);
    }
}
