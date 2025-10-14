<?php

declare(strict_types=1);

namespace Domain\DTO;

final readonly class VariantDTO
{
    public function __construct(
        public string $id,
        public int $price,
        public int $stock,
    ) {}
}
