<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Models\SazitoProduct;
use App\Models\SazitoVariant;
use App\Support\TitleNormalizer;
use Illuminate\Support\Carbon;

class UpsertSazitoCatalogueAction
{
    private const REFERENCE_KEYS = [
        'anar360_variant_id',
        'anar_variant_id',
        'anar360_product_id',
        'anar360_id',
        'anar_product_id',
        'anar_id',
        'anar360Id',
        'anarId',
        'external_id',
        'externalId',
        'integration_id',
        'integrationId',
        'foreign_id',
        'foreignId',
        'reference_id',
        'referenceId',
    ];

    /**
     * @param list<array<string, mixed>> $products
     * @return array{products_upserted:int, variants_upserted:int, mappings_attached:int, product_mappings_attached:int}
     */
    public function execute(array $products): array
    {
        $now = Carbon::now();

        $productsUpserted = 0;
        $variantsUpserted = 0;
        $mappingsAttached = 0;
        $productMappingsAttached = 0;

        foreach ($products as $product) {
            $sazitoId = (string) ($product['id'] ?? $product['sazito_id'] ?? $product['_id'] ?? $product['product_id'] ?? '');
            if ($sazitoId === '') {
                continue;
            }

            $title = $product['title'] ?? $product['name'] ?? null;
            $productModel = SazitoProduct::query()->firstOrNew(['sazito_id' => $sazitoId]);
            $originalProductAnarId = $productModel->anar360_product_id;

            $payload = [
                'title' => $title,
                'title_normalized' => TitleNormalizer::normalize(is_string($title) ? $title : null),
                'slug' => $product['slug'] ?? null,
                'raw_payload' => $product['raw'] ?? $product,
                'synced_at' => $now,
            ];

            $productReferences = $this->extractProductReferences($product);
            $resolvedProductId = $this->resolvePreferredExternalId($productReferences);

            if ($resolvedProductId !== null) {
                $payload['anar360_product_id'] = $resolvedProductId;
            }

            $productModel->fill($payload);
            $productModel->save();

            if ($resolvedProductId !== null && $resolvedProductId !== $originalProductAnarId) {
                $productMappingsAttached++;
            }

            $productsUpserted++;

            $variants = $product['variants'] ?? [];
            foreach ($variants as $variant) {
                $sazitoVariantId = (string) ($variant['id'] ?? $variant['sazito_id'] ?? $variant['_id'] ?? $variant['variant_id'] ?? '');
                if ($sazitoVariantId === '') {
                    continue;
                }

                $externalReferences = $this->extractExternalReferences($variant);
                $resolvedAnarId = $this->resolvePreferredExternalId($externalReferences);

                /** @var SazitoVariant $variantModel */
                $variantModel = $productModel->variants()->updateOrCreate(
                    ['sazito_id' => $sazitoVariantId],
                    [
                        'title' => $variant['title'] ?? $variant['name'] ?? null,
                        'sku' => $variant['sku'] ?? $variant['code'] ?? $variant['product_code'] ?? null,
                        'external_references' => $externalReferences ?: null,
                        'raw_payload' => $variant['raw'] ?? $variant,
                        'synced_at' => $now,
                    ],
                );

                if ($resolvedAnarId !== null && $variantModel->anar360_variant_id !== $resolvedAnarId) {
                    $variantModel->anar360_variant_id = $resolvedAnarId;
                    $variantModel->save();
                    $mappingsAttached++;
                }

                if ($variantModel->wasRecentlyCreated) {
                    $variantsUpserted++;
                }
            }
        }

        return [
            'products_upserted' => $productsUpserted,
            'variants_upserted' => $variantsUpserted,
            'mappings_attached' => $mappingsAttached + $productMappingsAttached,
            'product_mappings_attached' => $productMappingsAttached,
        ];
    }

    /**
     * @param array<string, mixed> $variant
     * @return list<string>
     */
    private function extractExternalReferences(array $variant, array $ignoredNestedKeys = []): array
    {
        $references = [];

        foreach (self::REFERENCE_KEYS as $key) {
            if (isset($variant[$key]) && is_scalar($variant[$key])) {
                $references[] = (string) $variant[$key];
            }
        }

        foreach (['metadata', 'meta', 'attributes', 'extras'] as $nestedKey) {
            $references = [
                ...$references,
                ...$this->collectReferencesFromNested($variant[$nestedKey] ?? null, $ignoredNestedKeys),
            ];
        }

        return array_values(array_unique(array_filter($references, static fn ($value) => $value !== '')));
    }

    /**
     * @param array<string, mixed> $product
     * @return list<string>
     */
    private function extractProductReferences(array $product): array
    {
        $ignoredKeys = ['variants', 'variations', 'product_variants', 'productVariants', 'variant_list', 'variantList'];

        $sanitized = $this->withoutKeys($product, [...$ignoredKeys, 'raw']);
        $references = $this->extractExternalReferences($sanitized, $ignoredKeys);

        $raw = $product['raw'] ?? null;
        if (is_array($raw)) {
            $rawSanitized = $this->withoutKeys($raw, $ignoredKeys);
            $references = [
                ...$references,
                ...$this->extractExternalReferences($rawSanitized, $ignoredKeys),
            ];
        }

        return array_values(array_unique($references));
    }

    /**
     * @return list<string>
     */
    private function collectReferencesFromNested(mixed $value, array $ignoredKeys = []): array
    {
        if (! is_array($value)) {
            return [];
        }

        $references = [];
        foreach (self::REFERENCE_KEYS as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) {
                $references[] = (string) $value[$key];
            }
        }

        foreach ($value as $key => $item) {
            if (in_array((string) $key, $ignoredKeys, true)) {
                continue;
            }

            if (is_array($item)) {
                $references = [
                    ...$references,
                    ...$this->collectReferencesFromNested($item, $ignoredKeys),
                ];
            }
        }

        return $references;
    }

    /**
     * @param list<string> $references
     */
    private function resolvePreferredExternalId(array $references): ?string
    {
        foreach ($references as $reference) {
            if (preg_match('/^[a-f0-9]{24}$/i', $reference) === 1) {
                return strtolower($reference);
            }
        }

        return $references[0] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function withoutKeys(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }
}
