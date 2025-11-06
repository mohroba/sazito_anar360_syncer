<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Services\Sazito\Exceptions\SazitoRequestException;
use App\Services\Sazito\SazitoClient;
use Throwable;

class UpsertSazitoProductAction
{
    public function __construct(
        private readonly SazitoClient $client,
        private readonly RecordEventAction $recordEvent,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function execute(string $runId, array $payload, ?string $productId = null, array $options = []): array
    {
        $mode = $productId === null ? 'create' : 'update';

        try {
            if ($productId === null) {
                $response = $this->client->createProduct($payload, [
                    ...$options,
                    'run_id' => $runId,
                ]);
                $refId = $response['product']['id'] ?? null;
            } else {
                $response = $this->client->updateProduct($productId, $payload, [
                    ...$options,
                    'run_id' => $runId,
                ]);
                $refId = $productId;
            }
        } catch (SazitoRequestException $exception) {
            $this->recordEvent->execute(
                $runId,
                'SAZITO_PRODUCT_UPSERTED',
                [
                    'mode' => $mode,
                    'product_id' => $productId,
                    'payload_keys' => array_keys($payload),
                    'status' => $exception->statusCode(),
                    'response' => $exception->responseBody(),
                ],
                $productId,
                'error',
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordEvent->execute(
                $runId,
                'SAZITO_PRODUCT_UPSERTED',
                [
                    'mode' => $mode,
                    'product_id' => $productId,
                    'payload_keys' => array_keys($payload),
                    'error' => $exception->getMessage(),
                ],
                $productId,
                'error',
            );

            throw $exception;
        }

        $this->recordEvent->execute(
            $runId,
            'SAZITO_PRODUCT_UPSERTED',
            [
                'mode' => $mode,
                'product_id' => $refId ?? $productId,
                'payload_keys' => array_keys($payload),
            ],
            $refId ?? $productId,
        );

        return $response;
    }
}
