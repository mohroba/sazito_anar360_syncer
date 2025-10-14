<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Models\SyncRun;
use App\Services\Anar360\Anar360Client;
use Domain\DTO\ProductDTO;
use Throwable;

class FetchProductsAction
{
    public function __construct(
        private readonly Anar360Client $client,
        private readonly RecordEventAction $recordEvent,
    ) {}

    /**
     * @return array{products: list<ProductDTO>, meta: array}
     */
    public function execute(SyncRun $run, int $page, int $limit, int $sinceMs): array
    {
        try {
            $response = $this->client->fetchProducts($page, $limit, $sinceMs, $run->id);
        } catch (Throwable $exception) {
            $this->recordEvent->execute($run->id, 'VALIDATION_FAILED', [
                'exception' => $exception->getMessage(),
            ], level: 'error');

            throw $exception;
        }

        $this->recordEvent->execute($run->id, 'PRODUCTS_FETCHED', [
            'page' => $page,
            'limit' => $limit,
            'count' => count($response['items']),
        ]);

        return [
            'products' => $response['items'],
            'meta' => $response['meta'],
        ];
    }
}
