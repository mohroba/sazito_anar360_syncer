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

        return $this->send('PUT', sprintf('/accounting/update-price/%s', $variantId), [
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
