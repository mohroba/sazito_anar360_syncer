<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Sync\PropagateSazitoOrderAction;
use App\Actions\Sync\RecordEventAction;
use App\Models\IntegrationEvent;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use App\Services\Sazito\SazitoClient;
use Mockery;
use Tests\TestCase;

class PropagateSazitoOrderActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_execute_create_path_records_event(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $client->shouldReceive('createOrder')
            ->once()
            ->with(
                ['items' => [['sku' => 'SKU-1', 'count' => 1, 'price' => 1000]], 'shipping_address' => [], 'shipping_items' => [], 'payment' => []],
                Mockery::on(static function (array $options): bool {
                    return ($options['run_id'] ?? null) === 'run-1';
                }),
            )
            ->andReturn(['order' => ['id' => '500']]);

        $events = Mockery::mock(RecordEventAction::class);
        $events->shouldReceive('execute')
            ->once()
            ->withArgs(static function (string $runId, string $type, array $payload, ?string $refId): bool {
                return $runId === 'run-1'
                    && $type === 'SAZITO_ORDER_PROPAGATED'
                    && $refId === '500'
                    && $payload['operation'] === 'create'
                    && $payload['order_id'] === '500';
            })
            ->andReturn(new IntegrationEvent());

        $action = new PropagateSazitoOrderAction($client, $events);
        $response = $action->execute('run-1', 'create', [
            'items' => [['sku' => 'SKU-1', 'count' => 1, 'price' => 1000]],
            'shipping_address' => [],
            'shipping_items' => [],
            'payment' => [],
        ]);

        $this->assertSame('500', $response['order']['id']);
    }

    public function test_execute_update_path_requires_id_and_records(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $client->shouldReceive('updateOrder')
            ->once()
            ->with(
                '700',
                ['order_identifier' => 'ORD-700'],
                Mockery::on(static function (array $options): bool {
                    return ($options['run_id'] ?? null) === 'run-2';
                }),
            )
            ->andReturn(['order' => ['id' => '700']]);

        $events = Mockery::mock(RecordEventAction::class);
        $events->shouldReceive('execute')
            ->once()
            ->withArgs(static function (string $runId, string $type, array $payload, ?string $refId): bool {
                return $runId === 'run-2'
                    && $type === 'SAZITO_ORDER_PROPAGATED'
                    && $refId === '700'
                    && $payload['operation'] === 'update'
                    && $payload['order_id'] === '700';
            })
            ->andReturn(new IntegrationEvent());

        $action = new PropagateSazitoOrderAction($client, $events);
        $response = $action->execute('run-2', 'update', ['order_identifier' => 'ORD-700'], '700');

        $this->assertSame('700', $response['order']['id']);
    }

    public function test_execute_rethrows_and_logs_on_failure(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $client->shouldReceive('updateOrder')->andThrow(new SazitoRequestException(400));

        $events = Mockery::mock(RecordEventAction::class);
        $events->shouldReceive('execute')
            ->once()
            ->with(
                'run-3',
                'SAZITO_ORDER_PROPAGATED',
                Mockery::on(static function (array $payload): bool {
                    return $payload['operation'] === 'update'
                        && $payload['status'] === 400;
                }),
                '800',
                'error'
            )
            ->andReturn(new IntegrationEvent());

        $action = new PropagateSazitoOrderAction($client, $events);

        $this->expectException(SazitoRequestException::class);
        $action->execute('run-3', 'update', ['order_identifier' => 'ORD-800'], '800');
    }

    public function test_execute_validates_operation(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $events = Mockery::mock(RecordEventAction::class);
        $action = new PropagateSazitoOrderAction($client, $events);

        $this->expectException(\InvalidArgumentException::class);
        $action->execute('run-4', 'invalid', []);
    }
}
