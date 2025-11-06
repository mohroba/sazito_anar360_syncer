<?php

declare(strict_types=1);

namespace App\Services\Sazito;

use App\Services\Http\HttpClientFactory;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use Illuminate\Support\Arr;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

class SazitoClient
{
    private const DRIVER = 'SAZITO';

    private ClientInterface $client;
    private string $orderAccessKey;

    public function __construct(
        HttpClientFactory $httpClientFactory,
        private readonly array $config,
    ) {
        $this->client = $httpClientFactory->make(
            $this->config['base_uri'],
            self::DRIVER,
            [
                'X-API-KEY' => $this->config['api_key'],
                'Accept' => 'application/json',
            ],
        );

        $this->orderAccessKey = (string) ($this->config['order_api_key'] ?? $this->config['api_key']);
    }

    /**
     * @throws SazitoRequestException
     */
    public function fetchProducts(int $page, int $limit, ?string $runId = null): array
    {
        $query = [
            'page' => $page,
        ];

        if ($limit > 0) {
            $query['page_size'] = $limit;
            $query['limit'] = $limit; // Backwards compatibility for legacy APIs.
        }

        try {
            $response = $this->client->request('GET', 'products', [
                'query' => $query,
                'run_id' => $runId,
            ]);
        } catch (GuzzleException $exception) {
            throw new SazitoRequestException(0, null, $exception->getMessage(), $exception);
        }

        $payload = $this->validateResponse('/products', $response);

        return $this->normalizeProductList($payload);
    }

    /**
     * @throws SazitoRequestException
     */
    public function putPrice(string $variantId, int $price, ?int $discountPrice = null, ?bool $hasRawPrice = null, array $options = []): array
    {
        $body = ['price' => $price];
        if ($hasRawPrice !== null) {
            $body['has_raw_price'] = $hasRawPrice;
        }
        if ($discountPrice !== null) {
            $body['discount_price'] = $discountPrice;
        }

        return $this->send('PUT', sprintf('accounting/update-price/%s', $variantId), [
            ...$options,
            'json' => $body,
        ]);
    }

    /**
     * @throws SazitoRequestException
     */
    public function putStock(string $variantId, int $stock, bool $isRelative = false, array $options = []): array
    {
        $body = [
            'is_stock_manager' => true,
            'stock_number' => $stock,
        ];

        if ($isRelative) {
            $body['is_relative'] = true;
        }

        return $this->send('PUT', sprintf('/accounting/update-stock/%s', $variantId), [
            ...$options,
            'json' => $body,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SazitoRequestException
     */
    public function fetchProduct(string $productId, array $options = []): array
    {
        return $this->send('GET', sprintf('/products/%s', $productId), $options);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @throws SazitoRequestException
     */
    public function createProduct(array $payload, array $options = []): array
    {
        $body = $this->prepareProductMutationPayload($payload);

        $response = $this->send('POST', 'products', [
            ...$options,
            'json' => $body,
        ]);

        return $this->normalizeProductMutationResponse($response);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @throws SazitoRequestException
     */
    public function updateProduct(string $productId, array $payload, array $options = []): array
    {
        if ($productId === '') {
            throw new \InvalidArgumentException('Product id must not be empty.');
        }

        $body = $this->prepareProductMutationPayload($payload, true);

        $response = $this->send('PUT', sprintf('products/%s', $productId), [
            ...$options,
            'json' => $body,
        ]);

        return $this->normalizeProductMutationResponse($response);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @throws SazitoRequestException
     */
    public function fetchOrders(array $query = [], ?string $runId = null): array
    {
        foreach ($query as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException(sprintf('Query parameter %s must be scalar or null.', (string) $key));
            }
        }

        $response = $this->send('GET', 'orders', [
            'query' => $query,
            'run_id' => $runId,
        ]);

        return $this->normalizeOrderList($response);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @throws SazitoRequestException
     */
    public function updateOrder(string $orderId, array $payload, array $options = []): array
    {
        if ($orderId === '') {
            throw new \InvalidArgumentException('Order id must not be empty.');
        }

        $body = $this->prepareOrderUpdatePayload($payload);

        $response = $this->send('PUT', sprintf('orders/%s', $orderId), [
            ...$options,
            'json' => $body,
        ]);

        return $this->normalizeOrderResponse($response);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @throws SazitoRequestException
     */
    public function createOrder(array $payload, array $options = []): array
    {
        $body = $this->prepareOrderCreationPayload($payload);

        $headers = $options['headers'] ?? [];
        if (! is_array($headers)) {
            throw new \InvalidArgumentException('Headers option must be an array.');
        }

        $options['headers'] = [
            ...$headers,
            'Access-Key' => $this->orderAccessKey,
        ];

        $response = $this->send('POST', 'orders/create_order', [
            ...$options,
            'json' => $body,
        ]);

        return $this->normalizeOrderResponse($response);
    }

    /**
     * @param list<array{id:int|string,price:int|float}> $variants
     * @param array<string, mixed> $options
     *
     * @throws SazitoRequestException
     */
    public function bulkUpdateVariantPrices(array $variants, array $options = []): array
    {
        $body = $this->prepareBulkVariantPayload($variants, 'price');

        return $this->send('PUT', 'accounting/bulk-update-price', [
            ...$options,
            'json' => $body,
        ]);
    }

    /**
     * @param list<array{id:int|string,stock:int}> $variants
     * @param array<string, mixed> $options
     *
     * @throws SazitoRequestException
     */
    public function bulkUpdateVariantStock(array $variants, array $options = []): array
    {
        $body = $this->prepareBulkVariantPayload($variants, 'stock');

        return $this->send('PUT', 'accounting/bulk-update-stock', [
            ...$options,
            'json' => $body,
        ]);
    }

    /**
     * @param array<string, scalar|int|float|bool|null> $payload
     * @param array<string, mixed> $options
     *
     * @throws SazitoRequestException
     */
    public function updateVariantBySku(string $sku, array $payload, array $options = []): array
    {
        $encodedSku = trim($sku);
        if ($encodedSku === '') {
            throw new \InvalidArgumentException('SKU must not be empty.');
        }

        if ($payload === []) {
            throw new \InvalidArgumentException('Payload must not be empty when updating a variant by SKU.');
        }

        $body = [];
        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Payload keys must be strings.');
            }

            if (! is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException(sprintf('Invalid value for key %s.', $key));
            }

            $body[$key] = $value;
        }

        return $this->send('PUT', sprintf('products/update_variant/sku/%s', rawurlencode($encodedSku)), [
            ...$options,
            'json' => $body,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws SazitoRequestException
     */
    private function send(string $method, string $uri, array $options): array
    {
        try {
            $response = $this->client->request($method, $uri, $options);
        } catch (GuzzleException $exception) {
            throw new SazitoRequestException(0, null, $exception->getMessage(), $exception);
        }

        return $this->validateResponse($uri, $response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SazitoRequestException
     */
    private function validateResponse(string $uri, ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = $body === '' ? [] : json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status === 409) {
            return $decoded;
        }

        if ($status >= 400) {
            throw new SazitoRequestException(
                $status,
                $decoded,
                sprintf('Sazito responded with status %d for %s', $status, $uri)
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepareProductMutationPayload(array $payload, bool $isUpdate = false): array
    {
        if (! isset($payload['product']) || ! is_array($payload['product'])) {
            throw new \InvalidArgumentException('Product payload must include a product definition array.');
        }

        $product = $payload['product'];
        if (! isset($product['name']) || ! is_string($product['name'])) {
            throw new \InvalidArgumentException('Product name is required.');
        }

        if (! $isUpdate && (! isset($product['product_type']) || ! is_string($product['product_type']))) {
            throw new \InvalidArgumentException('Product type is required when creating a product.');
        }

        if (! isset($payload['product_variants']) || ! is_array($payload['product_variants'])) {
            throw new \InvalidArgumentException('Product variants payload must be an array.');
        }

        $variants = [];
        foreach ($payload['product_variants'] as $index => $variant) {
            if (! is_array($variant)) {
                throw new \InvalidArgumentException(sprintf('Variant at index %d must be an array.', $index));
            }

            if (! $isUpdate && ! isset($variant['price'])) {
                throw new \InvalidArgumentException(sprintf('Variant at index %d must include a price.', $index));
            }

            $variants[] = $variant;
        }

        $body = $payload;
        $body['product_variants'] = $variants;

        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepareOrderCreationPayload(array $payload): array
    {
        if (! isset($payload['items']) || ! is_array($payload['items']) || $payload['items'] === []) {
            throw new \InvalidArgumentException('Order items must be a non-empty array.');
        }

        foreach ($payload['items'] as $index => $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException(sprintf('Order item at index %d must be an array.', $index));
            }

            foreach (['sku', 'count', 'price'] as $requiredKey) {
                if (! array_key_exists($requiredKey, $item)) {
                    throw new \InvalidArgumentException(sprintf('Order item at index %d must include %s.', $index, $requiredKey));
                }
            }
        }

        if (! isset($payload['shipping_address']) || ! is_array($payload['shipping_address'])) {
            throw new \InvalidArgumentException('Shipping address must be provided.');
        }

        if (! isset($payload['shipping_items']) || ! is_array($payload['shipping_items'])) {
            throw new \InvalidArgumentException('Shipping items must be provided as an array.');
        }

        if (! isset($payload['payment']) || ! is_array($payload['payment'])) {
            throw new \InvalidArgumentException('Payment information must be provided.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepareOrderUpdatePayload(array $payload): array
    {
        if (! isset($payload['order_identifier']) || ! is_string($payload['order_identifier']) || $payload['order_identifier'] === '') {
            throw new \InvalidArgumentException('Order identifier is required when updating an order.');
        }

        if (isset($payload['shippingItems']) && ! is_array($payload['shippingItems'])) {
            throw new \InvalidArgumentException('shippingItems must be an array when provided.');
        }

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @return array{variants: list<array<string, mixed>>}
     */
    private function prepareBulkVariantPayload(array $variants, string $primaryField): array
    {
        if ($variants === []) {
            throw new \InvalidArgumentException('At least one variant is required for bulk updates.');
        }

        $normalized = [];
        foreach ($variants as $index => $variant) {
            if (! is_array($variant)) {
                throw new \InvalidArgumentException(sprintf('Variant at index %d must be an array.', $index));
            }

            if (! isset($variant['id'])) {
                throw new \InvalidArgumentException(sprintf('Variant at index %d must include an id.', $index));
            }

            if (! isset($variant[$primaryField])) {
                throw new \InvalidArgumentException(sprintf('Variant at index %d must include %s.', $index, $primaryField));
            }

            $normalized[] = $variant;
        }

        return ['variants' => $normalized];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{product: array<string, mixed>}
     */
    private function normalizeProductMutationResponse(array $payload): array
    {
        $container = $payload['result'] ?? $payload['data'] ?? $payload;

        $product = $container['product'] ?? $container;
        if (! is_array($product)) {
            return ['product' => [
                'id' => null,
                'title' => null,
                'slug' => null,
                'variants' => [],
                'raw' => $product,
            ]];
        }

        return ['product' => $this->normalizeProduct($product)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function normalizeOrderList(array $payload): array
    {
        $container = $payload['result'] ?? $payload;
        if (! is_array($container)) {
            $container = [];
        }

        $rawItems = $container['orders'] ?? $container['items'] ?? $container['data'] ?? [];
        if (! is_array($rawItems)) {
            $rawItems = [];
        }

        $items = [];
        foreach ($rawItems as $order) {
            if (! is_array($order)) {
                continue;
            }

            $items[] = $this->normalizeOrder($order);
        }

        $meta = $container['meta'] ?? Arr::except($container, ['orders', 'items', 'data']);
        if (! is_array($meta)) {
            $meta = [];
        }

        return [
            'items' => $items,
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{order: array<string, mixed>}
     */
    private function normalizeOrderResponse(array $payload): array
    {
        $container = $payload['result'] ?? $payload['data'] ?? $payload;
        if (isset($container['order']) && is_array($container['order'])) {
            $order = $container['order'];
        } else {
            $order = is_array($container) ? $container : [];
        }

        return ['order' => $this->normalizeOrder($order)];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function normalizeOrder(array $order): array
    {
        $orderId = $this->extractIdentifier($order, ['id', '_id', 'order_id', 'uuid']);
        $identifier = $order['order_identifier'] ?? $order['identifier'] ?? null;

        $items = [];
        $rawItems = $order['items'] ?? $order['order_items'] ?? [];
        if (! is_array($rawItems)) {
            $rawItems = [];
        }

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = $this->normalizeOrderItem($item);
        }

        return [
            'id' => $orderId,
            'identifier' => is_scalar($identifier) ? (string) $identifier : null,
            'status' => $order['status'] ?? null,
            'items' => $items,
            'raw' => $order,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeOrderItem(array $item): array
    {
        $id = null;
        try {
            $id = $this->extractIdentifier($item, ['id', '_id', 'order_item_id', 'uuid']);
        } catch (\InvalidArgumentException) {
            $id = null;
        }

        $count = $item['count'] ?? $item['quantity'] ?? $item['amount'] ?? null;
        if (is_numeric($count)) {
            $count = (int) $count;
        } else {
            $count = null;
        }

        $price = $item['price'] ?? $item['final_price'] ?? null;
        if (is_numeric($price)) {
            $price = (int) round((float) $price);
        } else {
            $price = null;
        }

        return [
            'id' => $id,
            'sku' => $item['sku'] ?? $item['code'] ?? null,
            'variant_id' => $item['variant_id'] ?? $item['product_variant_id'] ?? null,
            'quantity' => $count,
            'price' => $price,
            'raw' => $item,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function normalizeProductList(array $payload): array
    {
        $items = [];

        $container = $payload['result'] ?? $payload;
        if (! is_array($container)) {
            $container = [];
        }

        $rawItems = $container['products'] ?? $container['items'] ?? $container['data'] ?? [];
        if (! is_array($rawItems)) {
            $rawItems = [];
        }

        foreach ($rawItems as $product) {
            if (! is_array($product)) {
                continue;
            }

            $items[] = $this->normalizeProduct($product);
        }

        $meta = $container['meta'] ?? Arr::except($container, ['products', 'items', 'data']);
        if (! is_array($meta)) {
            $meta = [];
        }

        $pageNumber = $container['page_number'] ?? $meta['page'] ?? null;
        if ($pageNumber !== null) {
            $meta['page'] = (int) $pageNumber;
        }

        $pageSize = $container['page_size'] ?? $meta['page_size'] ?? null;
        if ($pageSize !== null) {
            $meta['page_size'] = (int) $pageSize;
        }

        $totalCount = $container['total_count'] ?? $meta['total_count'] ?? null;
        if ($totalCount !== null) {
            $meta['total_count'] = (int) $totalCount;
        }

        if (! array_key_exists('has_more', $meta)
            && $pageNumber !== null
            && $pageSize !== null
            && $totalCount !== null
        ) {
            $meta['has_more'] = ((int) $pageNumber * (int) $pageSize) < (int) $totalCount;
        }

        if (! array_key_exists('next_page', $meta)
            && ($meta['has_more'] ?? false)
            && $pageNumber !== null
        ) {
            $meta['next_page'] = (int) $pageNumber + 1;
        }

        return [
            'items' => $items,
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function normalizeProduct(array $product): array
    {
        $productId = $this->extractIdentifier($product, ['id', '_id', 'sazito_id', 'product_id', 'uuid']);

        $variants = [];
        $rawVariants = $product['variants'] ?? $product['variations'] ?? $product['product_variants'] ?? [];
        if (! is_array($rawVariants)) {
            $rawVariants = [];
        }

        foreach ($rawVariants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $variants[] = $this->normalizeVariant($variant);
        }

        return [
            'id' => $productId,
            'title' => $product['title'] ?? $product['name'] ?? null,
            'slug' => $product['slug'] ?? null,
            'variants' => $variants,
            'raw' => $product,
        ];
    }

    /**
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    private function normalizeVariant(array $variant): array
    {
        $variantId = $this->extractIdentifier($variant, ['id', '_id', 'sazito_id', 'variant_id', 'uuid']);

        return [
            'id' => $variantId,
            'title' => $variant['title'] ?? $variant['name'] ?? null,
            'sku' => $variant['sku'] ?? $variant['code'] ?? $variant['product_code'] ?? null,
            'raw' => $variant,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function extractIdentifier(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (! isset($payload[$key])) {
                continue;
            }

            $value = $payload[$key];
            if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $stringValue = (string) $value;
                if ($stringValue !== '') {
                    return $stringValue;
                }
            }
        }

        throw new \InvalidArgumentException('Unable to determine identifier from payload.');
    }
}
