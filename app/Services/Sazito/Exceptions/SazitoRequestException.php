<?php

declare(strict_types=1);

namespace App\Services\Sazito\Exceptions;

use RuntimeException;
use Throwable;

class SazitoRequestException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly ?array $responseBody = null,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : 'Unexpected Sazito response.', 0, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function responseBody(): ?array
    {
        return $this->responseBody;
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }
}
