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

    public function execute(
        string $runId,
        string $variantId,
        int $stock,
        bool $isRelative = false,
        ?string $sourceVariantId = null,
        array $options = [],
    ): array
    {
        try {
            $response = $this->client->putStock($variantId, $stock, $isRelative, [
                ...$options,
                'run_id' => $runId,
            ]);
        } catch (SazitoRequestException $exception) {
            $payload = [
                'variant_id' => $variantId,
                'stock' => $stock,
                'is_relative' => $isRelative,
                'status' => $exception->statusCode(),
                'response' => $exception->responseBody(),
            ];

            if ($sourceVariantId !== null) {
                $payload['source_variant_id'] = $sourceVariantId;
            }

            $this->recordEvent->execute($runId, 'VARIANT_STOCK_UPDATED', $payload, $variantId, 'error');

            throw $exception;
        } catch (Throwable $exception) {
            $payload = [
                'variant_id' => $variantId,
                'stock' => $stock,
                'error' => $exception->getMessage(),
            ];

            if ($sourceVariantId !== null) {
                $payload['source_variant_id'] = $sourceVariantId;
            }

            $this->recordEvent->execute($runId, 'VARIANT_STOCK_UPDATED', $payload, $variantId, 'error');

            throw $exception;
        }

        $payload = [
            'variant_id' => $variantId,
            'stock' => $stock,
            'is_relative' => $isRelative,
        ];

        if ($sourceVariantId !== null) {
            $payload['source_variant_id'] = $sourceVariantId;
        }

        $this->recordEvent->execute($runId, 'VARIANT_STOCK_UPDATED', $payload, $variantId);

        return $response;
    }
}
