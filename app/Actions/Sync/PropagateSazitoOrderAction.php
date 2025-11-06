<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Services\Sazito\Exceptions\SazitoRequestException;
use App\Services\Sazito\SazitoClient;
use Throwable;

class PropagateSazitoOrderAction
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
    public function execute(
        string $runId,
        string $operation,
        array $payload,
        ?string $orderId = null,
        array $options = [],
    ): array {
        $normalizedOperation = strtolower($operation);
        if (! in_array($normalizedOperation, ['create', 'update'], true)) {
            throw new \InvalidArgumentException('Operation must be "create" or "update".');
        }

        if ($normalizedOperation === 'update' && ($orderId === null || $orderId === '')) {
            throw new \InvalidArgumentException('Order id is required when updating orders.');
        }

        try {
            if ($normalizedOperation === 'create') {
                $response = $this->client->createOrder($payload, [
                    ...$options,
                    'run_id' => $runId,
                ]);
                $resolvedOrderId = $response['order']['id'] ?? null;
            } else {
                $response = $this->client->updateOrder($orderId, $payload, [
                    ...$options,
                    'run_id' => $runId,
                ]);
                $resolvedOrderId = $orderId;
            }
        } catch (SazitoRequestException $exception) {
            $this->recordEvent->execute(
                $runId,
                'SAZITO_ORDER_PROPAGATED',
                [
                    'operation' => $normalizedOperation,
                    'order_id' => $orderId,
                    'order_identifier' => $payload['order_identifier'] ?? null,
                    'status' => $exception->statusCode(),
                    'response' => $exception->responseBody(),
                ],
                $orderId,
                'error',
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordEvent->execute(
                $runId,
                'SAZITO_ORDER_PROPAGATED',
                [
                    'operation' => $normalizedOperation,
                    'order_id' => $orderId,
                    'order_identifier' => $payload['order_identifier'] ?? null,
                    'error' => $exception->getMessage(),
                ],
                $orderId,
                'error',
            );

            throw $exception;
        }

        $this->recordEvent->execute(
            $runId,
            'SAZITO_ORDER_PROPAGATED',
            [
                'operation' => $normalizedOperation,
                'order_id' => $resolvedOrderId,
                'order_identifier' => $payload['order_identifier'] ?? null,
            ],
            $resolvedOrderId,
        );

        return $response;
    }
}
