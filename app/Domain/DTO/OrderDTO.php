<?php

declare(strict_types=1);

namespace Domain\DTO;

/**
 * Represents an order/fulfilment entity returned by Anar360.
 *
 * @phpstan-import-type OrderRaw from self
 */
final readonly class OrderDTO
{
    /**
     * @param  list<OrderItemDTO>  $items
     * @param  list<OrderShipmentDTO>  $shipments
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $type,
        public ?string $status,
        public array $items,
        public array $shipments,
        public ?OrderAddressDTO $address,
        public array $raw,
    ) {}
}
