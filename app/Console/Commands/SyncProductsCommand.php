<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Sync\BuildAnar360TaxonomyAction;
use App\Actions\Sync\FetchProductsAction;
use App\Actions\Sync\RecordEventAction;
use App\Actions\Sync\SyncOrdersAction;
use App\Actions\Sync\UpsertCursorAction;
use App\Jobs\UpdateVariantPriceJob;
use App\Jobs\UpdateVariantStockJob;
use App\Models\SazitoProduct;
use App\Models\SazitoVariant;
use App\Models\SyncRun;
use App\Support\TitleNormalizer;
use Domain\DTO\OrderAddressDTO;
use Domain\DTO\OrderCreateDTO;
use Domain\DTO\OrderItemDTO;
use Domain\DTO\OrderShipmentDTO;
use Domain\DTO\ProductDTO;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncProductsCommand extends Command
{
    protected $signature = 'sync:products {--since-ms=} {--page=1} {--limit=} {--run-scope=incremental}';

    protected $description = 'Sync products from Anar360 and update Sazito';

    public function __construct(
        private readonly FetchProductsAction $fetchProducts,
        private readonly BuildAnar360TaxonomyAction $buildTaxonomy,
        private readonly SyncOrdersAction $syncOrders,
        private readonly RecordEventAction $recordEvent,
        private readonly UpsertCursorAction $upsertCursor,
        private readonly Dispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sinceMs = (int) ($this->option('since-ms') ?? config('integrations.anar360.since_ms'));
        $page = (int) $this->option('page');
        $limit = (int) ($this->option('limit') ?? config('integrations.anar360.page_limit'));
        $scope = (string) $this->option('run-scope');

        if (! config('integrations.anar360.enabled', true)) {
            $this->warn('Anar360 integration disabled.');

            return self::SUCCESS;
        }

        $run = SyncRun::query()->create([
            'id' => (string) Str::ulid(),
            'started_at' => now(),
            'status' => 'running',
            'scope' => $scope,
            'since_ms' => $sinceMs,
            'page' => $page,
        ]);

        try {
            $taxonomy = $this->buildTaxonomy->execute($run, 1, $limit);
            $categoryMap = $taxonomy['categories'];
            $attributeMap = $taxonomy['attributes'];

            $result = $this->fetchProducts->execute($run, $page, $limit, $sinceMs);
            $meta = $result['meta'];

            $jobsDispatched = 0;
            foreach ($result['products'] as $product) {
                $sazitoProduct = $this->ensureSazitoProductMapping($product, $run);

                foreach ($product->variants as $variant) {
                    $mapping = SazitoVariant::query()->where('anar360_variant_id', $variant->id)->first();

                    if ($mapping === null) {
                        $this->recordEvent->execute($run->id, 'SKIPPED', [
                            'reason' => 'mapping-missing',
                            'variant_id' => $variant->id,
                            'product_id' => $product->id,
                            'categories' => $this->categoryNames($product, $categoryMap),
                            'attributes' => $this->attributeKeys($product, $attributeMap),
                        ], $variant->id, 'warning');

                        continue;
                    }

                    $this->dispatcher->dispatch(new UpdateVariantPriceJob(
                        $mapping->sazito_id,
                        $variant->price,
                        $run->id,
                        sourceVariantId: $variant->id,
                    ));
                    $this->dispatcher->dispatch(new UpdateVariantStockJob(
                        $mapping->sazito_id,
                        $variant->stock,
                        false,
                        $run->id,
                        sourceVariantId: $variant->id,
                    ));
                    $jobsDispatched += 2;
                }
            }

            $this->recordEvent->execute($run->id, 'PRODUCTS_FETCHED', [
                'jobs' => $jobsDispatched,
                'category_map_count' => count($categoryMap),
                'attribute_map_count' => count($attributeMap),
            ]);

            $pendingOrders = $this->pendingOrdersFromConfig();
            $ordersResult = $this->syncOrders->execute(
                $run,
                $pendingOrders,
                $page,
                (int) config('integrations.anar360.orders_page_limit', $limit),
            );

            $this->upsertCursor->execute('products.page', [
                'page' => $page,
            ]);

            $this->upsertCursor->execute('products.since', [
                'since_ms' => $sinceMs,
            ]);

            $meta = array_merge($meta, [
                'taxonomy' => $taxonomy['meta'],
                'orders' => [
                    'fetched' => count($ordersResult['fetched']['items']),
                    'submitted' => count($ordersResult['submitted']),
                ],
            ]);

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'page' => $meta['page'] ?? $page,
                'pages_total' => $meta['pages_total'] ?? null,
                'totals_json' => $meta,
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Sync completed');

        return self::SUCCESS;
    }

    private function ensureSazitoProductMapping(ProductDTO $product, SyncRun $run): ?SazitoProduct
    {
        $existing = SazitoProduct::query()->where('anar360_product_id', $product->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $normalizedTitle = TitleNormalizer::normalize($product->title);
        if ($normalizedTitle === null) {
            return null;
        }

        $candidate = SazitoProduct::query()
            ->where('title_normalized', $normalizedTitle)
            ->orderByDesc('synced_at')
            ->first();

        if ($candidate === null) {
            return null;
        }

        if ($candidate->anar360_product_id !== $product->id) {
            $candidate->anar360_product_id = $product->id;
            $candidate->save();
        }

        return $candidate;
    }

    /**
     * @param  array<string, \Domain\DTO\CategoryDTO>  $categoryMap
     * @return list<string>
     */
    private function categoryNames(ProductDTO $product, array $categoryMap): array
    {
        $names = [];
        foreach ($product->categoryIds as $categoryId) {
            $names[] = $categoryMap[$categoryId]->name ?? (string) $categoryId;
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, \Domain\DTO\AttributeDTO>  $attributeMap
     * @return list<string>
     */
    private function attributeKeys(ProductDTO $product, array $attributeMap): array
    {
        $attributes = Arr::get($product->metadata, 'attributes', []);
        if (! is_array($attributes)) {
            return [];
        }

        $keys = [];
        foreach (array_keys($attributes) as $key) {
            $keys[] = $attributeMap[(string) $key]->name ?? (string) $key;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<OrderCreateDTO>
     */
    private function pendingOrdersFromConfig(): array
    {
        $orders = [];

        foreach ((array) config('integrations.anar360.pending_orders', []) as $index => $rawOrder) {
            if (! is_array($rawOrder)) {
                Log::warning('Skipping invalid pending order configuration entry.', ['index' => $index]);

                continue;
            }

            $items = [];
            foreach ($rawOrder['items'] ?? [] as $item) {
                if (! is_array($item) || ! isset($item['variation'], $item['amount'])) {
                    continue;
                }

                $items[] = new OrderItemDTO(
                    (string) $item['variation'],
                    (int) $item['amount'],
                    is_array($item['info'] ?? null) ? $item['info'] : [],
                );
            }

            if ($items === []) {
                Log::warning('Skipping pending order without valid items.', ['index' => $index]);

                continue;
            }

            $addressData = $rawOrder['address'] ?? null;
            if (! is_array($addressData)) {
                Log::warning('Skipping pending order without address.', ['index' => $index]);

                continue;
            }

            $address = new OrderAddressDTO(
                (string) ($addressData['postalCode'] ?? ''),
                (string) ($addressData['detail'] ?? ''),
                (string) ($addressData['transFeree'] ?? ''),
                (string) ($addressData['transFereeMobile'] ?? ''),
                (string) ($addressData['city'] ?? ''),
                (string) ($addressData['province'] ?? ''),
            );

            $shipments = [];
            foreach ($rawOrder['shipments'] ?? [] as $shipment) {
                if (! is_array($shipment)) {
                    continue;
                }

                $shipments[] = new OrderShipmentDTO(
                    isset($shipment['shipmentId']) ? (string) $shipment['shipmentId'] : null,
                    isset($shipment['deliveryId']) ? (string) $shipment['deliveryId'] : null,
                    isset($shipment['shipmentsReferenceId']) ? (string) $shipment['shipmentsReferenceId'] : null,
                    isset($shipment['description']) ? (string) $shipment['description'] : null,
                );
            }

            $orders[] = new OrderCreateDTO(
                (string) ($rawOrder['type'] ?? 'retail'),
                $items,
                $address,
                $shipments,
                isset($rawOrder['idempotency_key']) ? (string) $rawOrder['idempotency_key'] : null,
            );
        }

        return $orders;
    }
}
