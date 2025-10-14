<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Anar360\Anar360Client;
use App\Services\Http\HttpClientFactory;
use Domain\DTO\ProductDTO;
use Domain\DTO\VariantDTO;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Mockery;
use Tests\TestCase;

class Anar360ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fetch_products_normalizes_ids_and_prices(): void
    {
        $payload = [
            'total' => 1,
            'skip' => 0,
            'limit' => 1,
            'items' => [
                [
                    'id' => 'product-123',
                    'title' => 'Sample Product',
                    'variants' => [
                        [
                            'id' => 'variant-1',
                            'priceForResell' => 237300,
                            'stock' => 4,
                        ],
                    ],
                ],
            ],
        ];

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);

        $client = new Client([
            'handler' => HandlerStack::create($mockHandler),
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://anar360.test', 'ANAR360', Mockery::type('array'))
            ->andReturn($client);

        $validator = $this->app->make(ValidatorFactory::class);

        $service = new Anar360Client($factory, $validator, [
            'base_uri' => 'https://anar360.test',
            'token' => 'token',
        ]);

        $result = $service->fetchProducts(1, 1, -120000);

        $this->assertCount(1, $result['items']);
        $product = $result['items'][0];
        $this->assertInstanceOf(ProductDTO::class, $product);
        $this->assertSame('product-123', $product->id);
        $this->assertSame('Sample Product', $product->title);
        $this->assertCount(1, $product->variants);
        $variant = $product->variants[0];
        $this->assertInstanceOf(VariantDTO::class, $variant);
        $this->assertSame('variant-1', $variant->id);
        $this->assertSame(237300, $variant->price);
        $this->assertSame(4, $variant->stock);
    }
}
