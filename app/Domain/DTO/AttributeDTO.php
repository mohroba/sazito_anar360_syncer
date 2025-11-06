<?php

declare(strict_types=1);

namespace Domain\DTO;

/**
 * Represents an Anar360 attribute definition.
 */
final readonly class AttributeDTO
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $values,
        public array $raw,
    ) {}
}
