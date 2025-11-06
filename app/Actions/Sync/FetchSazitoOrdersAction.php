<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Services\Sazito\SazitoClient;
use Throwable;

class FetchSazitoOrdersAction
{
    public function __construct(
        private readonly SazitoClient $client,
        private readonly RecordEventAction $recordEvent,
    ) {}

    /**
     * @param array<string, scalar|null> $query
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function execute(string $runId, array $query = []): array
    {
        try {
            $response = $this->client->fetchOrders($query, $runId);
        } catch (Throwable $exception) {
            $this->recordEvent->execute(
                $runId,
                'SAZITO_ORDERS_FETCHED',
                [
                    'query' => $query,
                    'error' => $exception->getMessage(),
                ],
                level: 'error',
            );

            throw $exception;
        }

        $this->recordEvent->execute(
            $runId,
            'SAZITO_ORDERS_FETCHED',
            [
                'query' => $query,
                'count' => count($response['items']),
            ],
        );

        return $response;
    }
}
