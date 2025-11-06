<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Services\Sazito\Exceptions\SazitoRequestException;
use App\Services\Sazito\SazitoClient;
use Throwable;

class BulkUpdateSazitoVariantsAction
{
    public function __construct(
        private readonly SazitoClient $client,
        private readonly RecordEventAction $recordEvent,
    ) {}

    /**
     * @param list<array{id:int|string,price:int|float}> $variants
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function updatePrices(string $runId, array $variants, array $options = []): array
    {
        return $this->executeBulk($runId, 'price', fn (array $opts) => $this->client->bulkUpdateVariantPrices($variants, $opts), $variants, $options);
    }

    /**
     * @param list<array{id:int|string,stock:int}> $variants
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function updateStock(string $runId, array $variants, array $options = []): array
    {
        return $this->executeBulk($runId, 'stock', fn (array $opts) => $this->client->bulkUpdateVariantStock($variants, $opts), $variants, $options);
    }

    /**
     * @param array<string, scalar|int|float|bool|null> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function updateBySku(string $runId, string $sku, array $payload, array $options = []): array
    {
        return $this->execute(
            $runId,
            'sku',
            ['sku' => $sku, 'payload_keys' => array_keys($payload)],
            fn (array $opts) => $this->client->updateVariantBySku($sku, $payload, $opts),
            $options,
        );
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $callback
     * @param list<array{id:int|string, mixed}> $variants
     *
     * @return array<string, mixed>
     */
    private function executeBulk(
        string $runId,
        string $mode,
        callable $callback,
        array $variants,
        array $options,
    ): array {
        return $this->execute(
            $runId,
            $mode,
            ['variants' => array_map(static fn ($variant) => $variant['id'] ?? null, $variants)],
            $callback,
            $options,
        );
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function execute(
        string $runId,
        string $mode,
        array $context,
        callable $callback,
        array $options,
    ): array {
        try {
            $response = $callback([
                ...$options,
                'run_id' => $runId,
            ]);
        } catch (SazitoRequestException $exception) {
            $this->recordEvent->execute(
                $runId,
                'SAZITO_VARIANTS_BULK_UPDATED',
                [
                    'mode' => $mode,
                    ...$context,
                    'status' => $exception->statusCode(),
                    'response' => $exception->responseBody(),
                ],
                level: 'error',
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordEvent->execute(
                $runId,
                'SAZITO_VARIANTS_BULK_UPDATED',
                [
                    'mode' => $mode,
                    ...$context,
                    'error' => $exception->getMessage(),
                ],
                level: 'error',
            );

            throw $exception;
        }

        $this->recordEvent->execute(
            $runId,
            'SAZITO_VARIANTS_BULK_UPDATED',
            [
                'mode' => $mode,
                ...$context,
            ],
        );

        return $response;
    }
}
