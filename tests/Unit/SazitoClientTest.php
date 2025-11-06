<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Http\HttpClientFactory;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use App\Services\Sazito\SazitoClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class SazitoClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_client_error_raises_exception(): void
    {
        $mockHandler = new MockHandler([
            new Response(422, ['Content-Type' => 'application/json'], json_encode(['error' => 'bad request'], JSON_THROW_ON_ERROR)),
        ]);

        $client = new Client([
            'handler' => HandlerStack::create($mockHandler),
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $this->expectException(SazitoRequestException::class);
        $this->expectExceptionMessage('Sazito responded with status 422 for accounting/update-price/variant-1');

        $service->putPrice('variant-1', 1000);
    }

    public function test_put_price_sends_optional_fields(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $service->putPrice('variant-1', 1000, 900, true);

        $this->assertCount(1, $container);
        $body = json_decode((string) $container[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'price' => 1000,
            'has_raw_price' => true,
            'discount_price' => 900,
        ], $body);
    }

    public function test_put_stock_omits_is_relative_by_default(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $service->putStock('variant-1', 5);

        $this->assertCount(1, $container);
        $body = json_decode((string) $container[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'is_stock_manager' => true,
            'stock_number' => 5,
        ], $body);
    }

    public function test_put_stock_includes_is_relative_when_true(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $service->putStock('variant-1', 3, true);

        $this->assertCount(1, $container);
        $body = json_decode((string) $container[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'is_stock_manager' => true,
            'stock_number' => 3,
            'is_relative' => true,
        ], $body);
    }

    public function test_fetch_products_adds_query_parameters(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => []], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $service->fetchProducts(2, 15);

        $this->assertCount(1, $container);
        $request = $container[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('page=2', (string) $request->getUri());
        $this->assertStringContainsString('limit=15', (string) $request->getUri());
    }

    public function test_fetch_product_hits_expected_endpoint(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'product-1'], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $service->fetchProduct('product-1');

        $this->assertCount(1, $container);
        $request = $container[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/products/product-1', $request->getUri()->getPath());
    }

    public function test_create_product_sends_payload_and_normalizes_response(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'result' => [
                    'product' => [
                        'id' => '42',
                        'name' => 'Created',
                        'slug' => 'created',
                        'product_variants' => [
                            ['id' => '900', 'title' => 'Default', 'sku' => 'SKU-1'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $response = $service->createProduct([
            'product' => [
                'name' => 'Example',
                'product_type' => 'simple',
            ],
            'product_variants' => [
                ['price' => 1000],
            ],
        ]);

        $this->assertSame('POST', $container[0]['request']->getMethod());
        $this->assertSame('products', ltrim($container[0]['request']->getUri()->getPath(), '/'));

        $body = json_decode((string) $container[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('product', $body);
        $this->assertArrayHasKey('product_variants', $body);

        $this->assertSame('42', $response['product']['id']);
        $this->assertSame('Created', $response['product']['title']);
        $this->assertSame('SKU-1', $response['product']['variants'][0]['sku']);
    }

    public function test_update_product_requires_id(): void
    {
        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn(new Client(['handler' => HandlerStack::create(new MockHandler()), 'http_errors' => false]));

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $service->updateProduct('', [
            'product' => ['name' => 'Example'],
            'product_variants' => [['price' => 1000]],
        ]);
    }

    public function test_update_product_puts_payload_to_expected_path(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'product' => [
                        'id' => '42',
                        'name' => 'Updated',
                        'product_variants' => [
                            ['id' => '900', 'title' => 'Default', 'sku' => 'SKU-1'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $response = $service->updateProduct('42', [
            'product' => [
                'name' => 'Updated',
            ],
            'product_variants' => [
                ['id' => '900', 'price' => 1200],
            ],
        ]);

        $this->assertSame('PUT', $container[0]['request']->getMethod());
        $this->assertSame('products/42', ltrim($container[0]['request']->getUri()->getPath(), '/'));

        $body = json_decode((string) $container[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1200, $body['product_variants'][0]['price']);

        $this->assertSame('Updated', $response['product']['title']);
    }

    public function test_fetch_orders_normalizes_structure(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'orders' => [[
                    'id' => '77',
                    'status' => 'paid',
                    'order_identifier' => 'ORD-77',
                    'items' => [[
                        'id' => '900',
                        'sku' => 'SKU-1',
                        'count' => 2,
                        'price' => 1500,
                    ]],
                ]],
                'meta' => ['page' => 1],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $response = $service->fetchOrders(['page' => 1]);

        $this->assertSame('GET', $container[0]['request']->getMethod());
        $this->assertStringContainsString('page=1', (string) $container[0]['request']->getUri());
        $this->assertSame('77', $response['items'][0]['id']);
        $this->assertSame('ORD-77', $response['items'][0]['identifier']);
        $this->assertSame(2, $response['items'][0]['items'][0]['quantity']);
    }

    public function test_create_order_sets_access_key_header(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'order' => [
                    'id' => '500',
                    'order_identifier' => 'ORD-500',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
            'order_api_key' => 'order-secret',
        ]);

        $response = $service->createOrder([
            'items' => [[
                'sku' => 'SKU-1',
                'count' => 1,
                'price' => 2000,
            ]],
            'shipping_address' => ['address' => 'X'],
            'shipping_items' => [],
            'payment' => ['gateway_name' => 'cash'],
        ]);

        $this->assertSame('POST', $container[0]['request']->getMethod());
        $this->assertSame('orders/create_order', ltrim($container[0]['request']->getUri()->getPath(), '/'));
        $this->assertSame('order-secret', $container[0]['request']->getHeaderLine('Access-Key'));
        $this->assertSame('500', $response['order']['id']);
    }

    public function test_update_order_requires_identifier(): void
    {
        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn(new Client(['handler' => HandlerStack::create(new MockHandler()), 'http_errors' => false]));

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $service->updateOrder('10', []);
    }

    public function test_bulk_update_variant_prices_sends_payload(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $service->bulkUpdateVariantPrices([
            ['id' => 1, 'price' => 1000],
        ]);

        $this->assertSame('PUT', $container[0]['request']->getMethod());
        $this->assertSame('accounting/bulk-update-price', ltrim($container[0]['request']->getUri()->getPath(), '/'));
        $body = json_decode((string) $container[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1000, $body['variants'][0]['price']);
    }

    public function test_update_variant_by_sku_validates_payload_and_encodes_path(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $factory = Mockery::mock(HttpClientFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with('https://sazito.test', 'SAZITO', Mockery::type('array'))
            ->andReturn($client);

        $service = new SazitoClient($factory, [
            'base_uri' => 'https://sazito.test',
            'api_key' => 'secret',
        ]);

        $service->updateVariantBySku('SKU 1/2', [
            'price' => 1500,
        ]);

        $this->assertSame('PUT', $container[0]['request']->getMethod());
        $this->assertSame('products/update_variant/sku/SKU%201%2F2', ltrim($container[0]['request']->getUri()->getPath(), '/'));
        $body = json_decode((string) $container[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1500, $body['price']);

        $this->expectException(\InvalidArgumentException::class);
        $service->updateVariantBySku('SKU-1', []);
    }
}
