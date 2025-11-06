<?php

declare(strict_types=1);

namespace Domain\DTO;

/**
 * Response from Anar360 after creating orders.
 */
final readonly class OrderSubmissionResultDTO
{
    /**
     * @param  list<OrderDTO>  $orders
     */
    public function __construct(
        public bool $success,
        public array $orders,
        public ?string $paymentLink,
        public ?string $message,
        public array $raw,
    ) {}
}
