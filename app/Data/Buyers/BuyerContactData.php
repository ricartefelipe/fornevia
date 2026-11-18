<?php

declare(strict_types=1);

namespace App\Data\Buyers;

final readonly class BuyerContactData
{
    public function __construct(
        public string $name,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $position = null,
        public bool $isPrimary = false,
    ) {}
}
