<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Anar360\Anar360Client;
use App\Services\Http\HttpClientFactory;
use Domain\DTO\AttributeDTO;
use Domain\DTO\CategoryDTO;
use Domain\DTO\OrderAddressDTO;
use Domain\DTO\OrderCreateDTO;
use Domain\DTO\OrderDTO;
use Domain\DTO\OrderItemDTO;
use Domain\DTO\OrderShipmentDTO;
use Domain\DTO\OrderSubmissionResultDTO;
use Domain\DTO\ProductDTO;
use Domain\DTO\VariantDTO;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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
                    'categories' => [
                        [
                            '_id' => 'cat-1',
                            'name' => 'Category One',
                        ],
                    ],
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
        $this->assertSame(['cat-1'], $product->categoryIds);
        $this->assertCount(1, $product->variants);
        $variant = $product->variants[0];
        $this->assertInstanceOf(VariantDTO::class, $variant);
        $this->assertSame('variant-1', $variant->id);
        $this->assertSame(237300, $variant->price);
        $this->assertSame(4, $variant->stock);
    }

    public function test_fetch_categories_returns_dtos_and_uses_query(): void
    {
        $payload = [
            'total' => 1,
            'skip' => 0,
            'limit' => 25,
            'items' => [
                [
                    'id' => 'cat-1',
                    'name' => 'Category One',
                    'attributeIds' => ['attr-1'],
                    'route' => ['Root', 'Category One'],
                ],
            ],
        ];

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);

        $history = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $handlerStack,
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

        $result = $service->fetchCategories(2, 10);

        $this->assertCount(1, $result['items']);
        $this->assertInstanceOf(CategoryDTO::class, $result['items'][0]);
        $this->assertSame('cat-1', $result['items'][0]->id);
        $this->assertSame(['attr-1'], $result['items'][0]->attributeIds);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('categories', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['page' => '2', 'limit' => '10'], $query);
    }

    public function test_fetch_attributes_returns_dtos(): void
    {
        $payload = [
            'total' => 1,
            'skip' => 0,
            'limit' => 25,
            'items' => [
                [
                    'key' => 'color',
                    'name' => 'Color',
                    'values' => ['Red', 'Blue'],
                ],
            ],
        ];

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);

        $history = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $handlerStack,
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

        $result = $service->fetchAttributes(1, 50);

        $this->assertCount(1, $result['items']);
        $attribute = $result['items'][0];
        $this->assertInstanceOf(AttributeDTO::class, $attribute);
        $this->assertSame('color', $attribute->key);
        $this->assertSame(['Red', 'Blue'], $attribute->values);

        $request = $history[0]['request'];
        $this->assertSame('attributes', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['page' => '1', 'limit' => '50'], $query);
    }

    public function test_fetch_orders_normalizes_payload(): void
    {
        $payload = [
            'total' => 1,
            'skip' => 0,
            'limit' => 25,
            'items' => [
                [
                    'id' => 'order-1',
                    'type' => 'retail',
                    'status' => 'pending',
                    'items' => [
                        [
                            'variation' => 'variant-1',
                            'amount' => 2,
                            'info' => ['color' => 'red'],
                        ],
                    ],
                    'shipments' => [
                        [
                            'shipmentId' => 'ship-1',
                            'deliveryId' => 'delivery-1',
                        ],
                    ],
                    'address' => [
                        'postalCode' => '12345',
                        'detail' => 'Street 1',
                        'transFeree' => 'John Doe',
                        'transFereeMobile' => '09120000000',
                        'city' => 'Tehran',
                        'province' => 'Tehran',
                    ],
                ],
            ],
        ];

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);

        $history = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $handlerStack,
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

        $result = $service->fetchOrders(1, 25);

        $this->assertCount(1, $result['items']);
        $order = $result['items'][0];
        $this->assertInstanceOf(OrderDTO::class, $order);
        $this->assertSame('order-1', $order->id);
        $this->assertSame('retail', $order->type);
        $this->assertSame('pending', $order->status);
        $this->assertCount(1, $order->items);
        $this->assertSame('variant-1', $order->items[0]->variationId);
        $this->assertSame(2, $order->items[0]->amount);
        $this->assertCount(1, $order->shipments);
        $this->assertSame('ship-1', $order->shipments[0]->shipmentId);
        $this->assertNotNull($order->address);
        $this->assertSame('12345', $order->address->postalCode);

        $request = $history[0]['request'];
        $this->assertSame('orders', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['page' => '1', 'limit' => '25'], $query);
    }

    public function test_create_order_sends_payload_and_parses_response(): void
    {
        $responsePayload = [
            'success' => true,
            'orders' => [
                [
                    '_id' => 'order-1',
                    'type' => 'retail',
                    'status' => 'pending',
                    'items' => [
                        [
                            'variation' => 'variant-1',
                            'amount' => 1,
                        ],
                    ],
                ],
            ],
            'paymentLink' => 'https://payments.test/1',
            'message' => 'Created',
        ];

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($responsePayload, JSON_THROW_ON_ERROR)),
        ]);

        $history = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $handlerStack,
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

        $order = new OrderCreateDTO(
            'retail',
            [new OrderItemDTO('variant-1', 1)],
            new OrderAddressDTO('12345', 'Street 1', 'John Doe', '09120000000', 'Tehran', 'Tehran'),
            [new OrderShipmentDTO('ship-1', 'delivery-1', 'ref-1', 'Standard shipping')],
            'idempotent-key',
        );

        $result = $service->createOrder($order);

        $this->assertInstanceOf(OrderSubmissionResultDTO::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('https://payments.test/1', $result->paymentLink);
        $this->assertSame('Created', $result->message);
        $this->assertCount(1, $result->orders);
        $this->assertInstanceOf(OrderDTO::class, $result->orders[0]);
        $this->assertSame('order-1', $result->orders[0]->id);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('orders', $request->getUri()->getPath());
        $this->assertSame('idempotent-key', $history[0]['options']['idempotency_key']);
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('retail', $body['type']);
        $this->assertSame('variant-1', $body['items'][0]['variation']);
        $this->assertSame(1, $body['items'][0]['amount']);
        $this->assertSame('ship-1', $body['shipments'][0]['shipmentId']);
    }
}
