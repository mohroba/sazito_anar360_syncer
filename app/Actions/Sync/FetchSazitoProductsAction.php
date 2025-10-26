<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Models\SyncRun;
use App\Services\Sazito\SazitoClient;
use Illuminate\Support\Arr;
use Throwable;

class FetchSazitoProductsAction
{
    public function __construct(
        private readonly SazitoClient $client,
        private readonly RecordEventAction $recordEvent,
    ) {}

    /**
     * @return array{products: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function execute(SyncRun $run, int $page, int $limit): array
    {
        try {
            $response = $this->client->fetchProducts($page, $limit, $run->id);
        } catch (Throwable $exception) {
            $this->recordEvent->execute($run->id, 'SAZITO_PRODUCTS_FETCH_FAILED', [
                'page' => $page,
                'limit' => $limit,
                'exception' => $exception->getMessage(),
            ], level: 'error');

            throw $exception;
        }

        $items = $response['items'] ?? [];
        $meta = $response['meta'] ?? [];

        $this->recordEvent->execute($run->id, 'SAZITO_PRODUCTS_FETCHED', [
            'page' => $page,
            'limit' => $limit,
            'count' => count($items),
            'meta' => Arr::only($meta, ['page', 'pages_total', 'has_more', 'next_page']),
        ]);

        return [
            'products' => $items,
            'meta' => $meta,
        ];
    }
}
