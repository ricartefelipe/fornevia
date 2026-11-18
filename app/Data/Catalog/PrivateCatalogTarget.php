<?php

declare(strict_types=1);

namespace App\Data\Catalog;

use InvalidArgumentException;

/**
 * Destino de uma atribuição de catálogo privado: exatamente uma empresa
 * compradora ou um grupo de clientes.
 */
final readonly class PrivateCatalogTarget
{
    private function __construct(
        public ?string $buyerCompanyId,
        public ?string $customerGroupId,
    ) {
        if (($buyerCompanyId === null) === ($customerGroupId === null)) {
            throw new InvalidArgumentException('Informe exatamente uma empresa compradora ou um grupo de clientes.');
        }
    }

    public static function buyerCompany(string $buyerCompanyId): self
    {
        return new self($buyerCompanyId, null);
    }

    public static function customerGroup(string $customerGroupId): self
    {
        return new self(null, $customerGroupId);
    }

    public function isBuyerCompany(): bool
    {
        return $this->buyerCompanyId !== null;
    }
}
