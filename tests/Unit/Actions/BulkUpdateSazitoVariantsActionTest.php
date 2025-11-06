<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Sync\BulkUpdateSazitoVariantsAction;
use App\Actions\Sync\RecordEventAction;
use App\Models\IntegrationEvent;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use App\Services\Sazito\SazitoClient;
use Mockery;
use Tests\TestCase;

class BulkUpdateSazitoVariantsActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_update_prices_records_success(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $client->shouldReceive('bulkUpdateVariantPrices')
            ->once()
            ->with(
                [['id' => 1, 'price' => 1000]],
                Mockery::on(static function (array $options): bool {
                    return ($options['run_id'] ?? null) === 'run-1';
                }),
            )
            ->andReturn(['ok' => true]);

        $events = Mockery::mock(RecordEventAction::class);
        $events->shouldReceive('execute')
            ->once()
            ->withArgs(static function (string $runId, string $type, array $payload): bool {
                return $runId === 'run-1'
                    && $type === 'SAZITO_VARIANTS_BULK_UPDATED'
                    && $payload === ['mode' => 'price', 'variants' => [1]];
            })
            ->andReturn(new IntegrationEvent());

        $action = new BulkUpdateSazitoVariantsAction($client, $events);
        $response = $action->updatePrices('run-1', [['id' => 1, 'price' => 1000]]);

        $this->assertTrue($response['ok']);
    }

    public function test_update_by_sku_logs_failure(): void
    {
        $client = Mockery::mock(SazitoClient::class);
        $client->shouldReceive('updateVariantBySku')->andThrow(new SazitoRequestException(422));

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
                    && $type === 'SAZITO_VARIANTS_BULK_UPDATED'
                    && $level === 'error'
                    && $payload['mode'] === 'sku'
                    && $payload['payload_keys'] === ['price']
                    && $payload['status'] === 422;
            })
            ->andReturn(new IntegrationEvent());

        $action = new BulkUpdateSazitoVariantsAction($client, $events);

        $this->expectException(SazitoRequestException::class);
        $action->updateBySku('run-2', 'SKU-1', ['price' => 1200]);
    }
}
