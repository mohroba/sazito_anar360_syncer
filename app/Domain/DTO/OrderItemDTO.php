<?php

declare(strict_types=1);

namespace Domain\DTO;

final readonly class OrderItemDTO
{
    /**
     * @param  array<string, mixed>  $info
     */
    public function __construct(
        public string $variationId,
        public int $amount,
        public array $info = [],
    ) {}
}
