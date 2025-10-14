<?php

declare(strict_types=1);

namespace Domain\DTO;

/**
 * @phpstan-type VariantArray array{id:string,price:int,stock:int}
 */
final readonly class ProductDTO
{
    /**
     * @param  list<VariantDTO>  $variants
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $variants,
    ) {}
}
