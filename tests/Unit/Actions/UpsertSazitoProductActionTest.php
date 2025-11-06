<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Sync\RecordEventAction;
use App\Actions\Sync\UpsertSazitoProductAction;
use App\Models\IntegrationEvent;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use App\Services\Sazito\SazitoClient;
use Mockery;
use Tests\TestCase;

class UpsertSazitoProductActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_execute_creates_product_and_records_event(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $client->shouldReceive('createProduct')
            ->once()
            ->with(
                ['product' => ['name' => 'Example', 'product_type' => 'simple'], 'product_variants' => [['price' => 1000]]],
                Mockery::on(static function (array $options): bool {
                    return ($options['run_id'] ?? null) === 'run-1';
                }),
            )
            ->andReturn(['product' => ['id' => '100']]);

        $events = Mockery::mock(RecordEventAction::class);
        $events->shouldReceive('execute')
            ->once()
            ->withArgs(static function (string $runId, string $type, array $payload, ?string $refId): bool {
                return $runId === 'run-1'
                    && $type === 'SAZITO_PRODUCT_UPSERTED'
                    && $refId === '100'
                    && $payload['mode'] === 'create'
                    && $payload['product_id'] === '100'
                    && $payload['payload_keys'] === ['product', 'product_variants'];
            })
            ->andReturn(new IntegrationEvent());

        $action = new UpsertSazitoProductAction($client, $events);

        $response = $action->execute('run-1', [
            'product' => ['name' => 'Example', 'product_type' => 'simple'],
            'product_variants' => [['price' => 1000]],
        ]);

        $this->assertSame('100', $response['product']['id']);
    }

    public function test_execute_updates_product_when_id_provided(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $client->shouldReceive('updateProduct')
            ->once()
            ->with(
                '200',
                ['product' => ['name' => 'Updated'], 'product_variants' => [['id' => 'V1', 'price' => 1200]]],
                Mockery::on(static function (array $options): bool {
                    return ($options['run_id'] ?? null) === 'run-2';
                }),
            )
            ->andReturn(['product' => ['id' => '200']]);

        $events = Mockery::mock(RecordEventAction::class);
        $events->shouldReceive('execute')
            ->once()
            ->withArgs(static function (string $runId, string $type, array $payload, ?string $refId): bool {
                return $runId === 'run-2'
                    && $type === 'SAZITO_PRODUCT_UPSERTED'
                    && $refId === '200'
                    && $payload['mode'] === 'update'
                    && $payload['product_id'] === '200';
            })
            ->andReturn(new IntegrationEvent());

        $action = new UpsertSazitoProductAction($client, $events);

        $response = $action->execute('run-2', [
            'product' => ['name' => 'Updated'],
            'product_variants' => [['id' => 'V1', 'price' => 1200]],
        ], '200');

        $this->assertSame('200', $response['product']['id']);
    }

    public function test_execute_records_failure_and_rethrows(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $client->shouldReceive('createProduct')->andThrow(new SazitoRequestException(422));

        $events = Mockery::mock(RecordEventAction::class);
        $events->shouldReceive('execute')
            ->once()
            ->with(
                'run-3',
                'SAZITO_PRODUCT_UPSERTED',
                Mockery::on(static function (array $payload): bool {
                    return $payload['mode'] === 'create'
                        && $payload['product_id'] === null
                        && $payload['status'] === 422;
                }),
                null,
                'error'
            )
            ->andReturn(new IntegrationEvent());

        $action = new UpsertSazitoProductAction($client, $events);

        $this->expectException(SazitoRequestException::class);
        $action->execute('run-3', [
            'product' => ['name' => 'Broken', 'product_type' => 'simple'],
            'product_variants' => [['price' => 1000]],
        ]);
    }
}
