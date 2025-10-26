<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Anar360\Anar360Client;
use App\Services\Sazito\SazitoClient;
use Domain\DTO\ProductDTO;
use Domain\DTO\VariantDTO;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class TestProductsCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_outputs_summary(): void
    {
        $anarMock = Mockery::mock(Anar360Client::class);
        $anarMock->shouldReceive('fetchProducts')
            ->once()
            ->with(1, 5, config('integrations.anar360.since_ms'))
            ->andReturn([
                'items' => [
                    new ProductDTO('product-1', 'Product One', [
                        new VariantDTO('variant-1', 1000, 3),
                    ]),
                ],
                'meta' => ['page' => 1],
            ]);
        $this->app->instance(Anar360Client::class, $anarMock);

        $sazitoMock = Mockery::mock(SazitoClient::class);
        $sazitoMock->shouldReceive('fetchProducts')
            ->once()
            ->withNoArgs()
            ->andReturn(['data' => [['id' => 'product-1']]]);
        $this->app->instance(SazitoClient::class, $sazitoMock);

        $exitCode = Artisan::call('integration:test-products');

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Fetched 1 product(s) from Anar360.', $output);
        $this->assertStringContainsString('Sazito response:', $output);
        $this->assertStringContainsString('product-1', $output);
    }
}
