<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Models\SyncRun;
use App\Services\Anar360\Anar360Client;
use Domain\DTO\OrderCreateDTO;
use Domain\DTO\OrderSubmissionResultDTO;
use Throwable;

class SyncOrdersAction
{
    public function __construct(
        private readonly Anar360Client $client,
        private readonly RecordEventAction $recordEvent,
    ) {}

    /**
     * @param  list<OrderCreateDTO>  $pendingOrders
     * @return array{fetched: array{items: list<\Domain\DTO\OrderDTO>, meta: array}, submitted: list<OrderSubmissionResultDTO>}
     */
    public function execute(SyncRun $run, array $pendingOrders, int $page, int $limit): array
    {
        $orders = $this->client->fetchOrders($page, $limit, $run->id);

        $this->recordEvent->execute($run->id, 'ANAR360_ORDERS_FETCHED', [
            'count' => count($orders['items']),
        ]);

        $submitted = [];
        foreach ($pendingOrders as $orderDraft) {
            try {
                $submitted[] = $this->client->createOrder($orderDraft, $run->id);
            } catch (Throwable $exception) {
                $this->recordEvent->execute($run->id, 'ANAR360_ORDER_SUBMIT_FAILED', [
                    'message' => $exception->getMessage(),
                ], level: 'error');
            }
        }

        if ($submitted !== []) {
            $this->recordEvent->execute($run->id, 'ANAR360_ORDERS_SUBMITTED', [
                'count' => count($submitted),
            ]);
        }

        return [
            'fetched' => $orders,
            'submitted' => $submitted,
        ];
    }
}
