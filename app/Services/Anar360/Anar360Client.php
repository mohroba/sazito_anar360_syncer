<?php

declare(strict_types=1);

namespace App\Services\Anar360;

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
use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

class Anar360Client
{
    private const DRIVER = 'ANAR360';

    public function __construct(
        private readonly HttpClientFactory $httpClientFactory,
        private readonly ValidatorFactory $validator,
        private readonly array $config,
    ) {}

    /**
     * @return array{items: list<ProductDTO>, meta: array}
     */
    public function fetchProducts(int $page, int $limit, int $sinceMs, ?string $runId = null): array
    {
        $client = $this->makeClient();

        $query = [
            'page' => $page,
            'limit' => $limit,
            'since' => $sinceMs,
        ];

        $this->logRequest('GET', 'products', $query);

        $response = $client->request('GET', 'products', [
            'query' => $query,
            'run_id' => $runId,
        ]);

        $this->logResponse($response);

        $rawPayload = $this->decode($response);
        $payload = $this->normalizeProductListPayload($rawPayload);
        $this->validateProductList($payload);

        $items = [];
        foreach ($payload['items'] as $product) {
            $variants = [];
            foreach ($product['variants'] as $variant) {
                $variants[] = new VariantDTO(
                    $variant['_id'],
                    (int) $variant['price'],
                    (int) $variant['stock'],
                );
            }

            $items[] = new ProductDTO(
                $product['_id'],
                $product['title'] ?? 'unknown',
                $variants,
                $product['category_ids'],
                $product['metadata'],
            );
        }

        return [
            'items' => $items,
            'meta' => Arr::except($rawPayload, ['items']),
        ];
    }

    public function fetchProduct(string $productId, ?string $runId = null): ProductDTO
    {
        $client = $this->makeClient();
        $response = $client->request('GET', sprintf('/products/%s', $productId), [
            'run_id' => $runId,
        ]);

        $payload = $this->normalizeProduct($this->decode($response));
        $this->validateProduct($payload);

        $variants = [];
        foreach ($payload['variants'] as $variant) {
            $variants[] = new VariantDTO(
                $variant['_id'],
                (int) $variant['price'],
                (int) $variant['stock'],
            );
        }

        return new ProductDTO(
            $payload['_id'],
            $payload['title'] ?? 'unknown',
            $variants,
            $payload['category_ids'],
            $payload['metadata'],
        );
    }

    /**
     * @return array{items: list<CategoryDTO>, meta: array}
     */
    public function fetchCategories(int $page, int $limit, ?string $runId = null): array
    {
        $client = $this->makeClient();

        $query = [
            'page' => $page,
            'limit' => $limit,
        ];

        $response = $client->request('GET', 'categories', [
            'query' => $query,
            'run_id' => $runId,
        ]);

        $payload = $this->normalizeCategoryListPayload($this->decode($response));
        $this->validateCategoryList($payload);

        $items = [];
        foreach ($payload['items'] as $category) {
            $items[] = new CategoryDTO(
                $category['_id'],
                $category['name'],
                $category['attributeIds'],
                $category['parent'],
                $category['route'],
                Arr::except($category, ['_id', 'name', 'attributeIds', 'parent', 'route']),
            );
        }

        return [
            'items' => $items,
            'meta' => Arr::except($payload, ['items']),
        ];
    }

    /**
     * @return array{items: list<AttributeDTO>, meta: array}
     */
    public function fetchAttributes(int $page, int $limit, ?string $runId = null): array
    {
        $client = $this->makeClient();

        $query = [
            'page' => $page,
            'limit' => $limit,
        ];

        $response = $client->request('GET', 'attributes', [
            'query' => $query,
            'run_id' => $runId,
        ]);

        $payload = $this->normalizeAttributeListPayload($this->decode($response));
        $this->validateAttributeList($payload);

        $items = [];
        foreach ($payload['items'] as $attribute) {
            $items[] = new AttributeDTO(
                $attribute['key'],
                $attribute['name'],
                $attribute['values'],
                Arr::except($attribute, ['key', 'name', 'values']),
            );
        }

        return [
            'items' => $items,
            'meta' => Arr::except($payload, ['items']),
        ];
    }

    /**
     * @return array{items: list<OrderDTO>, meta: array}
     */
    public function fetchOrders(int $page, int $limit, ?string $runId = null): array
    {
        $client = $this->makeClient();

        $query = [
            'page' => $page,
            'limit' => $limit,
        ];

        $response = $client->request('GET', 'orders', [
            'query' => $query,
            'run_id' => $runId,
        ]);

        $payload = $this->normalizeOrderListPayload($this->decode($response));
        $this->validateOrderList($payload);

        $items = [];
        foreach ($payload['items'] as $order) {
            $items[] = $this->mapToOrderDto($order);
        }

        return [
            'items' => $items,
            'meta' => Arr::except($payload, ['items']),
        ];
    }

    public function createOrder(OrderCreateDTO $order, ?string $runId = null): OrderSubmissionResultDTO
    {
        $client = $this->makeClient();

        $payload = $this->buildOrderPayload($order);
        $this->validateOrderCreateRequest($payload);

        $options = [
            'json' => $payload,
            'run_id' => $runId,
        ];

        if ($order->idempotencyKey !== null) {
            $options['idempotency_key'] = $order->idempotencyKey;
        }

        $response = $client->request('POST', 'orders', $options);

        $rawResponse = $this->decode($response);
        $this->validateOrderCreateResponse($rawResponse);

        $orders = [];
        foreach ($rawResponse['orders'] ?? [] as $rawOrder) {
            $orders[] = $this->mapToOrderDto($this->normalizeOrder($rawOrder));
        }

        return new OrderSubmissionResultDTO(
            (bool) ($rawResponse['success'] ?? false),
            $orders,
            $rawResponse['paymentLink'] ?? null,
            $rawResponse['message'] ?? null,
            Arr::except($rawResponse, ['success', 'orders', 'paymentLink', 'message']),
        );
    }

    private function makeClient(): ClientInterface
    {
        return $this->httpClientFactory->make(
            $this->config['base_uri'],
            self::DRIVER,
            [
                'Authorization' => sprintf('Bearer %s', $this->config['token']),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{items: list<array<string, mixed>>}
     */
    private function normalizeProductListPayload(array $payload): array
    {
        $items = [];
        foreach ($payload['items'] ?? [] as $product) {
            $items[] = $this->normalizeProduct($product);
        }

        $payload['items'] = $items;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{items: list<array<string, mixed>>}
     */
    private function normalizeCategoryListPayload(array $payload): array
    {
        $items = [];
        foreach ($payload['items'] ?? [] as $category) {
            $items[] = $this->normalizeCategory($category);
        }

        $payload['items'] = $items;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{items: list<array<string, mixed>>}
     */
    private function normalizeAttributeListPayload(array $payload): array
    {
        $items = [];
        foreach ($payload['items'] ?? [] as $attribute) {
            $items[] = $this->normalizeAttribute($attribute);
        }

        $payload['items'] = $items;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{items: list<array<string, mixed>>}
     */
    private function normalizeOrderListPayload(array $payload): array
    {
        $items = [];
        foreach ($payload['items'] ?? [] as $order) {
            $items[] = $this->normalizeOrder($order);
        }

        $payload['items'] = $items;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function normalizeProduct(array $product): array
    {
        if (! isset($product['_id']) && isset($product['id'])) {
            $product['_id'] = (string) $product['id'];
        }

        $variants = [];
        foreach ($product['variants'] ?? [] as $variant) {
            $variants[] = $this->normalizeVariant($variant);
        }

        $product['variants'] = $variants;
        $product['category_ids'] = array_values(array_filter(array_map(
            static fn (array $category): ?string => isset($category['_id']) ? (string) $category['_id'] : null,
            $product['categories'] ?? [],
        )));

        $product['metadata'] = Arr::except($product, ['_id', 'id', 'title', 'variants', 'category_ids']);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<string, mixed>
     */
    private function normalizeCategory(array $category): array
    {
        if (! isset($category['_id']) && isset($category['id'])) {
            $category['_id'] = (string) $category['id'];
        }

        $category['attributeIds'] = array_values(array_map('strval', $category['attributeIds'] ?? []));
        $category['route'] = array_values(array_map('strval', $category['route'] ?? []));
        $category['parent'] = isset($category['parent']) ? (string) $category['parent'] : null;

        return $category;
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @return array<string, mixed>
     */
    private function normalizeAttribute(array $attribute): array
    {
        $attribute['values'] = array_values(array_map('strval', $attribute['values'] ?? []));

        if (isset($attribute['key'])) {
            $attribute['key'] = (string) $attribute['key'];
        }

        if (isset($attribute['name'])) {
            $attribute['name'] = (string) $attribute['name'];
        }

        return $attribute;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function normalizeOrder(array $order): array
    {
        if (! isset($order['_id']) && isset($order['id'])) {
            $order['_id'] = (string) $order['id'];
        }

        $items = [];
        foreach ($order['items'] ?? [] as $item) {
            $items[] = $this->normalizeOrderItem($item);
        }
        $order['items'] = $items;

        $shipments = [];
        foreach ($order['shipments'] ?? [] as $shipment) {
            $shipments[] = $this->normalizeOrderShipment($shipment);
        }
        $order['shipments'] = $shipments;

        if (isset($order['address']) && is_array($order['address'])) {
            $order['address'] = $this->normalizeOrderAddress($order['address']);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private function normalizeVariant(array $variant): array
    {
        if (! isset($variant['_id']) && isset($variant['id'])) {
            $variant['_id'] = (string) $variant['id'];
        }

        if (! array_key_exists('price', $variant) && array_key_exists('priceForResell', $variant)) {
            $variant['price'] = $variant['priceForResell'];
        }

        $variant['stock'] = (int) ($variant['stock'] ?? 0);

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeOrderItem(array $item): array
    {
        if (isset($item['variation'])) {
            $item['variation'] = (string) $item['variation'];
        }

        $item['amount'] = (int) ($item['amount'] ?? 0);
        $item['info'] = is_array($item['info'] ?? null) ? $item['info'] : [];

        return $item;
    }

    /**
     * @param  array<string, mixed>  $shipment
     * @return array<string, mixed>
     */
    private function normalizeOrderShipment(array $shipment): array
    {
        foreach (['shipmentId', 'deliveryId', 'shipmentsReferenceId', 'description'] as $key) {
            if (isset($shipment[$key])) {
                $shipment[$key] = (string) $shipment[$key];
            }
        }

        return $shipment;
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function normalizeOrderAddress(array $address): array
    {
        foreach (['postalCode', 'detail', 'transFeree', 'transFereeMobile', 'city', 'province'] as $key) {
            if (isset($address[$key])) {
                $address[$key] = (string) $address[$key];
            }
        }

        return $address;
    }

    private function validateProductList(array $payload): void
    {
        $validator = $this->validator->make($payload, [
            'items' => 'required|array',
            'items.*._id' => 'required|string',
            'items.*.title' => 'nullable|string',
            'items.*.variants' => 'required|array',
            'items.*.variants.*._id' => 'required|string',
            'items.*.variants.*.price' => 'required|numeric|min:0',
            'items.*.variants.*.stock' => 'required|integer',
            'items.*.category_ids' => 'array',
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid Anar360 product payload: '.$validator->errors()->first());
        }
    }

    private function validateProduct(array $payload): void
    {
        $validator = $this->validator->make($payload, [
            '_id' => 'required|string',
            'title' => 'nullable|string',
            'variants' => 'required|array',
            'variants.*._id' => 'required|string',
            'variants.*.price' => 'required|numeric|min:0',
            'variants.*.stock' => 'required|integer',
            'category_ids' => 'array',
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid Anar360 product payload: '.$validator->errors()->first());
        }
    }

    private function validateCategoryList(array $payload): void
    {
        $validator = $this->validator->make($payload, [
            'items' => 'required|array',
            'items.*._id' => 'required|string',
            'items.*.name' => 'required|string',
            'items.*.attributeIds' => 'array',
            'items.*.route' => 'array',
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid Anar360 category payload: '.$validator->errors()->first());
        }
    }

    private function validateAttributeList(array $payload): void
    {
        $validator = $this->validator->make($payload, [
            'items' => 'required|array',
            'items.*.key' => 'required|string',
            'items.*.name' => 'required|string',
            'items.*.values' => 'array',
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid Anar360 attribute payload: '.$validator->errors()->first());
        }
    }

    private function validateOrderList(array $payload): void
    {
        $validator = $this->validator->make($payload, [
            'items' => 'required|array',
            'items.*._id' => 'required|string',
            'items.*.items' => 'array',
            'items.*.items.*.variation' => 'required|string',
            'items.*.items.*.amount' => 'required|integer',
            'items.*.shipments' => 'array',
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid Anar360 order payload: '.$validator->errors()->first());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateOrderCreateRequest(array $payload): void
    {
        $validator = $this->validator->make($payload, [
            'type' => 'required|string|in:retail',
            'items' => 'required|array|min:1',
            'items.*.variation' => 'required|string',
            'items.*.amount' => 'required|integer|min:1',
            'items.*.info' => 'array',
            'address' => 'required|array',
            'address.postalCode' => 'required|string',
            'address.detail' => 'required|string',
            'address.transFeree' => 'required|string',
            'address.transFereeMobile' => 'required|string',
            'address.city' => 'required|string',
            'address.province' => 'required|string',
            'shipments' => 'array',
            'shipments.*.shipmentId' => 'nullable|string',
            'shipments.*.deliveryId' => 'nullable|string',
            'shipments.*.shipmentsReferenceId' => 'nullable|string',
            'shipments.*.description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid Anar360 order create payload: '.$validator->errors()->first());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateOrderCreateResponse(array $payload): void
    {
        $validator = $this->validator->make($payload, [
            'success' => 'required|boolean',
            'orders' => 'array',
            'orders.*._id' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid Anar360 order response: '.$validator->errors()->first());
        }
    }

    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderPayload(OrderCreateDTO $order): array
    {
        $items = [];
        foreach ($order->items as $item) {
            $items[] = [
                'variation' => $item->variationId,
                'amount' => $item->amount,
                'info' => $item->info,
            ];
        }

        $shipments = [];
        foreach ($order->shipments as $shipment) {
            $shipments[] = [
                'shipmentId' => $shipment->shipmentId,
                'deliveryId' => $shipment->deliveryId,
                'shipmentsReferenceId' => $shipment->shipmentsReferenceId,
                'description' => $shipment->description,
            ];
        }

        return [
            'type' => $order->type,
            'items' => $items,
            'address' => [
                'postalCode' => $order->address->postalCode,
                'detail' => $order->address->detail,
                'transFeree' => $order->address->transferee,
                'transFereeMobile' => $order->address->transfereeMobile,
                'city' => $order->address->city,
                'province' => $order->address->province,
            ],
            'shipments' => $shipments,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function mapToOrderDto(array $order): OrderDTO
    {
        $items = [];
        foreach ($order['items'] ?? [] as $item) {
            $items[] = new OrderItemDTO(
                $item['variation'],
                (int) $item['amount'],
                $item['info'] ?? [],
            );
        }

        $shipments = [];
        foreach ($order['shipments'] ?? [] as $shipment) {
            $shipments[] = new OrderShipmentDTO(
                $shipment['shipmentId'] ?? null,
                $shipment['deliveryId'] ?? null,
                $shipment['shipmentsReferenceId'] ?? null,
                $shipment['description'] ?? null,
            );
        }

        $address = null;
        if (isset($order['address']) && is_array($order['address'])) {
            $address = new OrderAddressDTO(
                $order['address']['postalCode'] ?? '',
                $order['address']['detail'] ?? '',
                $order['address']['transFeree'] ?? '',
                $order['address']['transFereeMobile'] ?? '',
                $order['address']['city'] ?? '',
                $order['address']['province'] ?? '',
            );
        }

        return new OrderDTO(
            $order['_id'],
            $order['type'] ?? null,
            $order['status'] ?? null,
            $items,
            $shipments,
            $address,
            Arr::except($order, ['_id', 'type', 'status', 'items', 'shipments', 'address']),
        );
    }

    private function logRequest(string $method, string $endpoint, array $query): void
    {
        Log::debug('[Anar360] Sending request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'query' => $query,
        ]);
    }

    private function logResponse(ResponseInterface $response): void
    {
        Log::debug('[Anar360] Response received', [
            'status' => $response->getStatusCode(),
            'body_snippet' => (string) $response->getBody(),
        ]);
    }
}
