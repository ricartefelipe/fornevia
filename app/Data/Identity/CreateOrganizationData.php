<?php

declare(strict_types=1);

namespace App\Data\Identity;

use App\Domain\Shared\Cnpj;

final readonly class CreateOrganizationData
{
    public function __construct(
        public string $legalName,
        public string $tradeName,
        public Cnpj $document,
        public string $planCode,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
