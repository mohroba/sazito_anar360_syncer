<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Anar360\Anar360Client;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use App\Services\Sazito\SazitoClient;
use Domain\DTO\ProductDTO;
use Domain\DTO\VariantDTO;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class TestProductsCommand extends Command
{
    protected $signature = 'integration:test-products
        {--page=1 : Page number to request from Anar360.}
        {--limit=5 : Number of products to request from Anar360.}
        {--since-ms= : Optional override for the Anar360 "since" parameter in milliseconds.}
        {--sazito-product= : Optional product identifier to fetch directly from Sazito.}
        {--json : Output the payload as JSON instead of tables.}';

    protected $description = 'Fetch products from Anar360 and Sazito for connectivity diagnostics.';

    public function __construct(
        private readonly Anar360Client $anar360Client,
        private readonly SazitoClient $sazitoClient,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $page = max(1, (int) $this->option('page'));
        $limit = max(1, (int) $this->option('limit'));
        $sinceMs = $this->option('since-ms');
        $sinceMs = (int) ($sinceMs ?? config('integrations.anar360.since_ms'));
        $sazitoProductId = $this->option('sazito-product');
        $jsonOutput = (bool) $this->option('json');

        try {
            $anarResponse = $this->anar360Client->fetchProducts($page, $limit, $sinceMs);
        } catch (Throwable $exception) {
            $this->error(sprintf('Failed to fetch Anar360 products: %s', $exception->getMessage()));

            return self::FAILURE;
        }

        $anarProducts = array_map(static function (ProductDTO $product): array {
            return [
                'id' => $product->id,
                'title' => $product->title,
                'variants' => implode(', ', array_map(
                    static fn (VariantDTO $variant): string => sprintf(
                        '%s (price:%d stock:%d)',
                        $variant->id,
                        $variant->price,
                        $variant->stock
                    ),
                    $product->variants
                )),
            ];
        }, $anarResponse['items']);

        try {
            $sazitoResponse = $sazitoProductId
                ? $this->sazitoClient->fetchProduct((string) $sazitoProductId)
                : $this->sazitoClient->fetchProducts(1, (int) config('integrations.sazito.page_size', 100));
        } catch (SazitoRequestException $exception) {
            $this->error(sprintf('Failed to fetch Sazito products: %s', $exception->getMessage()));

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error(sprintf('Unexpected Sazito failure: %s', $exception->getMessage()));

            return self::FAILURE;
        }

        if ($jsonOutput) {
            $payload = [
                'anar360' => [
                    'meta' => $anarResponse['meta'],
                    'products' => array_map(static fn (ProductDTO $product): array => [
                        'id' => $product->id,
                        'title' => $product->title,
                        'variants' => array_map(static fn (VariantDTO $variant): array => [
                            'id' => $variant->id,
                            'price' => $variant->price,
                            'stock' => $variant->stock,
                        ], $product->variants),
                    ], $anarResponse['items']),
                ],
                'sazito' => $sazitoResponse,
            ];

            try {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $this->error(sprintf('Unable to encode payload as JSON: %s', $exception->getMessage()));

                return self::FAILURE;
            }
        } else {
            $this->info(sprintf('Fetched %d product(s) from Anar360.', count($anarProducts)));
            if ($anarProducts !== []) {
                $this->table(['ID', 'Title', 'Variants'], $anarProducts);
            }

            $this->info('Sazito response:');
            $this->line($this->formatSazitoResponse($sazitoResponse));
        }

        return self::SUCCESS;
    }

    /**
     * @param array<mixed>|scalar|null $response
     */
    private function formatSazitoResponse(mixed $response): string
    {
        if (is_array($response)) {
            try {
                return json_encode($response, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return print_r($response, true);
            }
        }

        if (is_scalar($response) || $response === null) {
            return (string) $response;
        }

        return print_r($response, true);
    }
}
