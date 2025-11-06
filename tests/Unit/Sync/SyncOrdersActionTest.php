<?php

declare(strict_types=1);

namespace Tests\Unit\Sync;

use App\Actions\Sync\RecordEventAction;
use App\Actions\Sync\SyncOrdersAction;
use App\Models\SyncRun;
use App\Services\Anar360\Anar360Client;
use Domain\DTO\OrderAddressDTO;
use Domain\DTO\OrderCreateDTO;
use Domain\DTO\OrderDTO;
use Domain\DTO\OrderItemDTO;
use Domain\DTO\OrderShipmentDTO;
use Domain\DTO\OrderSubmissionResultDTO;
use Mockery;
use Tests\TestCase;

class SyncOrdersActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_execute_fetches_and_submits_orders(): void
    {
        $client = Mockery::mock(Anar360Client::class);
        $recordEvent = Mockery::mock(RecordEventAction::class);

        $run = SyncRun::make(['id' => 'run-1']);

        $client->shouldReceive('fetchOrders')
            ->once()
            ->with(1, 25, 'run-1')
            ->andReturn([
                'items' => [
                    new OrderDTO('order-1', 'retail', 'pending', [], [], null, []),
                ],
                'meta' => ['total' => 1],
            ]);

        $client->shouldReceive('createOrder')
            ->once()
            ->andReturn(new OrderSubmissionResultDTO(true, [], null, null, []));

        $recordEvent->shouldReceive('execute')
            ->once()
            ->with('run-1', 'ANAR360_ORDERS_FETCHED', Mockery::subset(['count' => 1]));

        $recordEvent->shouldReceive('execute')
            ->once()
            ->with('run-1', 'ANAR360_ORDERS_SUBMITTED', Mockery::subset(['count' => 1]));

        $action = new SyncOrdersAction($client, $recordEvent);

        $pending = [
            new OrderCreateDTO(
                'retail',
                [new OrderItemDTO('variant-1', 1)],
                new OrderAddressDTO('12345', 'Street', 'John', '0900', 'City', 'Province'),
                [new OrderShipmentDTO('ship-1', 'del-1', 'ref-1', null)],
            ),
        ];

        $result = $action->execute($run, $pending, 1, 25);

        $this->assertArrayHasKey('fetched', $result);
        $this->assertArrayHasKey('submitted', $result);
        $this->assertCount(1, $result['fetched']['items']);
        $this->assertCount(1, $result['submitted']);
    }
}
