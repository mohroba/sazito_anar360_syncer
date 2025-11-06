<?php

declare(strict_types=1);

namespace Domain\DTO;

final readonly class OrderShipmentDTO
{
    public function __construct(
        public ?string $shipmentId,
        public ?string $deliveryId,
        public ?string $shipmentsReferenceId,
        public ?string $description,
    ) {}
}
