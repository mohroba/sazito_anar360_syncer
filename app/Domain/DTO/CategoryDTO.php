<?php

declare(strict_types=1);

namespace Domain\DTO;

/**
 * Represents an Anar360 category for mapping purposes.
 *
 * @phpstan-type AttributeId string
 */
final readonly class CategoryDTO
{
    /**
     * @param  list<string>  $attributeIds
     * @param  list<string>  $route
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $attributeIds,
        public ?string $parentId,
        public array $route,
        public array $raw,
    ) {}
}
