<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Sync\FetchSazitoOrdersAction;
use App\Actions\Sync\RecordEventAction;
use App\Models\IntegrationEvent;
use App\Services\Sazito\SazitoClient;
use Mockery;
use Tests\TestCase;

class FetchSazitoOrdersActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_execute_fetches_and_records_event(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $client->shouldReceive('fetchOrders')
            ->once()
            ->with(['page' => 2], 'run-1')
            ->andReturn(['items' => [['id' => '10']], 'meta' => []]);

        $events = Mockery::mock(RecordEventAction::class);
        $events->shouldReceive('execute')
            ->once()
            ->withArgs(static function (string $runId, string $type, array $payload): bool {
                return $runId === 'run-1'
                    && $type === 'SAZITO_ORDERS_FETCHED'
                    && $payload === ['query' => ['page' => 2], 'count' => 1];
            })
            ->andReturn(new IntegrationEvent());

        $action = new FetchSazitoOrdersAction($client, $events);
        $response = $action->execute('run-1', ['page' => 2]);

        $this->assertSame('10', $response['items'][0]['id']);
    }

    public function test_execute_records_error_on_failure(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $client->shouldReceive('fetchOrders')->andThrow(new \RuntimeException('boom'));

        $events = Mockery::mock(RecordEventAction::class);
        $events->shouldReceive('execute')
            ->once()
            ->withArgs(static function (
                string $runId,
                string $type,
                array $payload,
                ?string $refId,
                string $level,
            ): bool {
                return $runId === 'run-2'
                    && $type === 'SAZITO_ORDERS_FETCHED'
                    && $level === 'error'
                    && $payload['query'] === ['page' => 1]
                    && $payload['error'] === 'boom';
            })
            ->andReturn(new IntegrationEvent());

        $action = new FetchSazitoOrdersAction($client, $events);

        $this->expectException(\RuntimeException::class);
        $action->execute('run-2', ['page' => 1]);
    }
}
