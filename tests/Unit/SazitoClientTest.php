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
}
