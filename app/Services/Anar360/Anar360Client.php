<?php

declare(strict_types=1);

namespace App\Services\Anar360;

use App\Services\Http\HttpClientFactory;
use Domain\DTO\ProductDTO;
use Domain\DTO\VariantDTO;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

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
        $client = $this->httpClientFactory->make(
            $this->config['base_uri'],
            self::DRIVER,
            [
                'Authorization' => sprintf('Bearer %s', $this->config['token']),
            ],
        );

        Log::debug('[Anar360] Sending GET request', [
            'base_uri' => $this->config['base_uri'],
            'endpoint' => '/products',
            'full_url' => rtrim($this->config['base_uri'], '/') . '/products?' . http_build_query([
                    'page' => $page,
                    'limit' => $limit,
                    'since' => $sinceMs,
                ]),
            'headers' => [
                'Authorization' => 'Bearer ' . substr($this->config['token'], 0, 15) . '...', // mask token
            ],
            'query' => [
                'page' => $page,
                'limit' => $limit,
                'since' => $sinceMs,
            ],
            'run_id' => $runId,
        ]);

        $response = $client->request('GET', 'products', [
            'query' => [
                'page' => $page,
                'limit' => $limit,
                'since' => $sinceMs,
            ],
            'run_id' => $runId,
        ]);

        Log::debug('[Anar360] Response received', [
            'status' => $response->getStatusCode(),
            'body_snippet' => substr((string) $response->getBody(), 0, 500),
        ]);

        $rawPayload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $payload = $this->normalizeListPayload($rawPayload);

        $this->validate($payload);

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

            $items[] = new ProductDTO($product['_id'], $product['title'] ?? 'unknown', $variants);
        }

        return [
            'items' => $items,
            'meta' => Arr::except($rawPayload, ['items']),
        ];
    }

    public function fetchProduct(string $productId, ?string $runId = null): ProductDTO
    {
        $client = $this->httpClientFactory->make(
            $this->config['base_uri'],
            self::DRIVER,
            [
                'Authorization' => sprintf('Bearer %s', $this->config['token']),
            ],
        );

        $response = $client->request('GET', sprintf('/products/%s', $productId), [
            'run_id' => $runId,
        ]);

        $rawPayload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $payload = $this->normalizeProduct($rawPayload);

        $this->validate($payload);

        $variants = [];
        foreach ($payload['variants'] as $variant) {
            $variants[] = new VariantDTO(
                $variant['_id'],
                (int) $variant['price'],
                (int) $variant['stock'],
            );
        }

        return new ProductDTO($payload['_id'], $payload['title'] ?? 'unknown', $variants);
    }

    public function validate(array $payload): void
    {
        if (isset($payload['items'])) {
            $validator = $this->validator->make($payload, [
                'items' => 'required|array',
                'items.*._id' => 'required|string',
                'items.*.title' => 'nullable|string',
                'items.*.variants' => 'required|array',
                'items.*.variants.*._id' => 'required|string',
                'items.*.variants.*.price' => 'required|integer|min:0',
                'items.*.variants.*.stock' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                throw new \InvalidArgumentException('Invalid Anar360 payload: '.$validator->errors()->first());
            }

            return;
        }

        $validator = $this->validator->make($payload, [
            '_id' => 'required|string',
            'title' => 'nullable|string',
            'variants' => 'required|array',
            'variants.*._id' => 'required|string',
            'variants.*.price' => 'required|integer|min:0',
            'variants.*.stock' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException('Invalid Anar360 payload: '.$validator->errors()->first());
        }
    }

    /**
     * @param array<mixed> $payload
     * @return array{items: list<array<string, mixed>>}
     */
    private function normalizeListPayload(array $payload): array
    {
        $items = [];
        foreach ($payload['items'] ?? [] as $product) {
            $items[] = $this->normalizeProduct($product);
        }

        $payload['items'] = $items;

        return $payload;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function normalizeProduct(array $product): array
    {
        if (!isset($product['_id']) && isset($product['id'])) {
            $product['_id'] = (string) $product['id'];
        }

        $variants = [];
        foreach ($product['variants'] ?? [] as $variant) {
            $variants[] = $this->normalizeVariant($variant);
        }

        $product['variants'] = $variants;

        return $product;
    }

    /**
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    private function normalizeVariant(array $variant): array
    {
        if (!isset($variant['_id']) && isset($variant['id'])) {
            $variant['_id'] = (string) $variant['id'];
        }

        if (!array_key_exists('price', $variant) && array_key_exists('priceForResell', $variant)) {
            $variant['price'] = $variant['priceForResell'];
        }

        return $variant;
    }
}
