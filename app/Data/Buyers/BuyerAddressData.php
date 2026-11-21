<?php

declare(strict_types=1);

namespace App\Data\Buyers;

use App\Enums\Buyers\AddressKind;

/**
 * Endereço de cobrança ou entrega. CEP e UF chegam como digitados e são
 * validados pela Action; a unidade (opcional) precisa ser da mesma empresa.
 */
final readonly class BuyerAddressData
{
    public function __construct(
        public AddressKind $kind,
        public string $label,
        public string $street,
        public string $number,
        public string $district,
        public string $city,
        public string $state,
        public string $postalCode,
        public ?string $recipient = null,
        public ?string $complement = null,
        public ?string $buyerUnitId = null,
        public bool $isDefault = false,
        public bool $isActive = true,
    ) {}
}
