<?php

declare(strict_types=1);

namespace App\Data\Buyers;

use App\Domain\Shared\Cnpj;

/**
 * Dados cadastrais de uma empresa compradora. Identificadores de grupo e de
 * representante são entrada não confiável: a Action os valida no tenant.
 */
final readonly class BuyerCompanyData
{
    public function __construct(
        public string $legalName,
        public string $tradeName,
        public Cnpj $document,
        public ?string $stateRegistration = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $notes = null,
        public ?string $customerGroupId = null,
        public ?string $salesRepMembershipId = null,
    ) {}
}
