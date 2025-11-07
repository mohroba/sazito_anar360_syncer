<?php

declare(strict_types=1);

namespace Tests\Unit\Documentation;

use App\Services\Anar360\Anar360Client;
use App\Services\Sazito\SazitoClient;
use Tests\TestCase;

final class ApiCoverageTest extends TestCase
{
    public function test_anar360_documentation_matches_client_surface(): void
    {
        $doc = $this->loadEndpointMap(base_path('docs/anar360_official_api.json'));

        $expected = [
            ['GET', '/api/360/auth/validate', Anar360Client::class, 'validateCredentials'],
            ['GET', '/api/360/products', Anar360Client::class, 'fetchProducts'],
            ['GET', '/api/360/products/{id}', Anar360Client::class, 'fetchProduct'],
            ['GET', '/api/360/categories', Anar360Client::class, 'fetchCategories'],
            ['GET', '/api/360/attributes', Anar360Client::class, 'fetchAttributes'],
            ['GET', '/api/360/orders', Anar360Client::class, 'fetchOrders'],
            ['GET', '/api/360/orders/{id}', Anar360Client::class, 'fetchOrder'],
            ['POST', '/api/360/orders', Anar360Client::class, 'createOrder'],
        ];

        foreach ($expected as [$method, $path, $class, $clientMethod]) {
            $key = $this->endpointKey($method, $path);
            $this->assertArrayHasKey(
                $key,
                $doc,
                sprintf('Documented endpoints missing %s %s in Anar360 docs.', $method, $path)
            );
            $this->assertTrue(
                method_exists($class, $clientMethod),
                sprintf('Client %s is missing method %s for %s %s', $class, $clientMethod, $method, $path)
            );
        }
    }

    public function test_sazito_documentation_matches_client_surface(): void
    {
        $doc = $this->loadEndpointMap(base_path("docs/sazito_api_endpoints_with_fields(1).json"));

        $expected = [
            ['GET', '/api/v1/products', SazitoClient::class, 'fetchProducts'],
            ['GET', '/api/v1/products/{product_id}', SazitoClient::class, 'fetchProduct'],
            ['POST', '/api/v1/products', SazitoClient::class, 'createProduct'],
            ['PUT', '/api/v1/products/{product_id}', SazitoClient::class, 'updateProduct'],
            ['GET', '/api/v1/orders', SazitoClient::class, 'fetchOrders'],
            ['PUT', '/api/v1/orders/{order_id}', SazitoClient::class, 'updateOrder'],
            ['POST', '/api/v1/orders/create_order', SazitoClient::class, 'createOrder'],
            ['PUT', '/api/v1/accounting/update-price/{variant_id}', SazitoClient::class, 'putPrice'],
            ['PUT', '/api/v1/accounting/update-stock/{variant_id}', SazitoClient::class, 'putStock'],
            ['PUT', '/api/v1/accounting/bulk-update-price', SazitoClient::class, 'bulkUpdateVariantPrices'],
            ['PUT', '/api/v1/accounting/bulk-update-stock', SazitoClient::class, 'bulkUpdateVariantStock'],
            ['PUT', '/api/v1/products/update_variant/sku/{SKU}', SazitoClient::class, 'updateVariantBySku'],
        ];

        foreach ($expected as [$method, $path, $class, $clientMethod]) {
            $key = $this->endpointKey($method, $path);
            $this->assertArrayHasKey(
                $key,
                $doc,
                sprintf('Documented endpoints missing %s %s in Sazito docs.', $method, $path)
            );
            $this->assertTrue(
                method_exists($class, $clientMethod),
                sprintf('Client %s is missing method %s for %s %s', $class, $clientMethod, $method, $path)
            );
        }
    }

    public function test_mapping_file_references_documented_endpoints(): void
    {
        $anarDoc = $this->loadEndpointMap(base_path('docs/anar360_official_api.json'));
        $sazitoDoc = $this->loadEndpointMap(base_path("docs/sazito_api_endpoints_with_fields(1).json"));

        $mapping = json_decode(file_get_contents(base_path('docs/anar360_to_sazito_sample_mapping.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ($mapping['mappings'] as $index => $section) {
            if (isset($section['source_endpoint'])) {
                $this->assertEndpointExistsInDocs($section['source_endpoint'], $anarDoc, 'source', $index);
            }

            if (isset($section['target_endpoint'])) {
                $this->assertEndpointExistsInDocs($section['target_endpoint'], $sazitoDoc, 'target', $index);
            }

            if (isset($section['alternate_target'])) {
                $this->assertEndpointExistsInDocs($section['alternate_target'], $sazitoDoc, 'alternate target', $index);
            }

            if (isset($section['target_endpoints']) && is_array($section['target_endpoints'])) {
                foreach ($section['target_endpoints'] as $endpoint) {
                    $this->assertEndpointExistsInDocs($endpoint, $sazitoDoc, 'target', $index);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $endpoint
     * @param array<string, array<string, mixed>> $docs
     */
    private function assertEndpointExistsInDocs(array $endpoint, array $docs, string $role, int $index): void
    {
        $method = strtoupper((string) ($endpoint['method'] ?? ''));
        $path = (string) ($endpoint['path'] ?? '');

        if ($method === '' || $path === '' || $method === '—') {
            return;
        }

        $key = $this->endpointKey($method, $path);
        $this->assertArrayHasKey(
            $key,
            $docs,
            sprintf('Mapping[%d] references %s endpoint %s %s not present in documentation.', $index, $role, $method, $path)
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadEndpointMap(string $path): array
    {
        $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $map = [];
        foreach ($decoded['endpoints'] as $endpoint) {
            $map[$this->endpointKey($endpoint['method'], $endpoint['path'])] = $endpoint;
        }

        return $map;
    }

    private function endpointKey(string $method, string $path): string
    {
        return sprintf('%s %s', strtoupper($method), $path);
    }
}
