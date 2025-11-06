<?php

declare(strict_types=1);

namespace Domain\DTO;

final readonly class OrderCreateDTO
{
    /**
     * @param  list<OrderItemDTO>  $items
     * @param  list<OrderShipmentDTO>  $shipments
     */
    public function __construct(
        public string $type,
        public array $items,
        public OrderAddressDTO $address,
        public array $shipments = [],
        public ?string $idempotencyKey = null,
    ) {}
}
