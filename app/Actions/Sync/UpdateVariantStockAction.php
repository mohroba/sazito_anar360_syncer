<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Services\Sazito\Exceptions\SazitoRequestException;
use App\Services\Sazito\SazitoClient;
use Throwable;

class UpdateVariantStockAction
{
    public function __construct(
        private readonly SazitoClient $client,
        private readonly RecordEventAction $recordEvent,
    ) {}

    public function execute(string $runId, string $variantId, int $stock, bool $isRelative = false, array $options = []): array
    {
        try {
            $response = $this->client->putStock($variantId, $stock, $isRelative, [
                ...$options,
                'run_id' => $runId,
            ]);
        } catch (SazitoRequestException $exception) {
            $this->recordEvent->execute($runId, 'VARIANT_STOCK_UPDATED', [
                'variant_id' => $variantId,
                'stock' => $stock,
                'is_relative' => $isRelative,
                'status' => $exception->statusCode(),
                'response' => $exception->responseBody(),
            ], $variantId, 'error');

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordEvent->execute($runId, 'VARIANT_STOCK_UPDATED', [
                'variant_id' => $variantId,
                'stock' => $stock,
                'error' => $exception->getMessage(),
            ], $variantId, 'error');

            throw $exception;
        }

        $this->recordEvent->execute($runId, 'VARIANT_STOCK_UPDATED', [
            'variant_id' => $variantId,
            'stock' => $stock,
            'is_relative' => $isRelative,
        ], $variantId);

        return $response;
    }
}
