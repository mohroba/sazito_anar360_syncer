<?php

declare(strict_types=1);

namespace Domain\DTO;

final readonly class OrderAddressDTO
{
    public function __construct(
        public string $postalCode,
        public string $detail,
        public string $transferee,
        public string $transfereeMobile,
        public string $city,
        public string $province,
    ) {}
}
