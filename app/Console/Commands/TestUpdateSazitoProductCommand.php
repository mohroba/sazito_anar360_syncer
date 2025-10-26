<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Sync\UpdateVariantPriceAction;
use App\Actions\Sync\UpdateVariantStockAction;
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
        $variantId = (string) $this->argument('variant');
        $priceOption = $this->option('price');
        $stockOption = $this->option('stock');
        $discountOption = $this->option('discount');
        $hasRawPriceOption = $this->option('has-raw-price');
        $isRelative = (bool) $this->option('relative');

        if ($priceOption === null && $stockOption === null) {
            $this->error('You must provide at least one of --price or --stock.');

            return self::FAILURE;
        }

        $runId = (string) Str::ulid();

        if ($priceOption !== null) {
            $price = (int) $priceOption;
            $discountPrice = $discountOption !== null ? (int) $discountOption : null;
            $hasRawPrice = $this->normalizeBooleanOption($hasRawPriceOption);

            try {
                $this->updateVariantPrice->execute($runId, $variantId, $price, $discountPrice, $hasRawPrice);
                $this->info(sprintf('Price update request sent for %s.', $variantId));
            } catch (SazitoRequestException $exception) {
                $this->error(sprintf('Sazito rejected the price update: %s', $exception->getMessage()));

                return self::FAILURE;
            } catch (Throwable $exception) {
                $this->error(sprintf('Unexpected price update failure: %s', $exception->getMessage()));

                return self::FAILURE;
            }
        }

        if ($stockOption !== null) {
            $stock = (int) $stockOption;

            try {
                $this->updateVariantStock->execute($runId, $variantId, $stock, $isRelative);
                $this->info(sprintf('Stock update request sent for %s.', $variantId));
            } catch (SazitoRequestException $exception) {
                $this->error(sprintf('Sazito rejected the stock update: %s', $exception->getMessage()));

                return self::FAILURE;
            } catch (Throwable $exception) {
                $this->error(sprintf('Unexpected stock update failure: %s', $exception->getMessage()));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function normalizeBooleanOption(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower((string) $value);

        return match ($value) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }
}
