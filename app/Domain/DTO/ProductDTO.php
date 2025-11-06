<?php

declare(strict_types=1);

namespace Domain\DTO;

final readonly class ProductDTO
{
    /**
     * @param  list<VariantDTO>  $variants
     * @param  list<string>  $categoryIds
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $variants,
        public array $categoryIds = [],
        public array $metadata = [],
    ) {}
}
