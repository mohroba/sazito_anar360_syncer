<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Models\SyncRun;
use App\Services\Anar360\Anar360Client;
use Domain\DTO\AttributeDTO;
use Domain\DTO\CategoryDTO;

class BuildAnar360TaxonomyAction
{
    public function __construct(
        private readonly Anar360Client $client,
        private readonly RecordEventAction $recordEvent,
    ) {}

    /**
     * @return array{categories: array<string, CategoryDTO>, attributes: array<string, AttributeDTO>, meta: array}
     */
    public function execute(SyncRun $run, int $page, int $limit): array
    {
        $categoriesResponse = $this->client->fetchCategories($page, $limit, $run->id);
        $attributesResponse = $this->client->fetchAttributes($page, $limit, $run->id);

        $categoryMap = [];
        foreach ($categoriesResponse['items'] as $category) {
            $categoryMap[$category->id] = $category;
        }

        $attributeMap = [];
        foreach ($attributesResponse['items'] as $attribute) {
            $attributeMap[$attribute->key] = $attribute;
        }

        $this->recordEvent->execute($run->id, 'ANAR360_TAXONOMY_FETCHED', [
            'category_count' => count($categoryMap),
            'attribute_count' => count($attributeMap),
        ]);

        return [
            'categories' => $categoryMap,
            'attributes' => $attributeMap,
            'meta' => [
                'categories' => $categoriesResponse['meta'],
                'attributes' => $attributesResponse['meta'],
            ],
        ];
    }
}
