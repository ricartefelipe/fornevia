<?php

declare(strict_types=1);

namespace App\Data\Ordering\Portal;

/**
 * Opções de checkout da empresa compradora (somente registros ativos da
 * própria empresa): unidades, endereços, centros de custo e condições de
 * pagamento permitidas. Cada lista é identificador => rótulo.
 */
final readonly class CheckoutChoices
{
    /**
     * @param  array<string, string>  $units
     * @param  array<string, string>  $shippingAddresses
     * @param  array<string, string>  $billingAddresses
     * @param  array<string, string>  $costCenters
     * @param  array<string, string>  $paymentTerms
     */
    public function __construct(
        public array $units,
        public array $shippingAddresses,
        public array $billingAddresses,
        public array $costCenters,
        public array $paymentTerms,
        public ?string $defaultShippingAddressId,
        public ?string $defaultBillingAddressId,
        public ?string $defaultPaymentTermId,
    ) {}

    public function hasUnit(string $id): bool
    {
        return isset($this->units[$id]);
    }

    public function hasShippingAddress(string $id): bool
    {
        return isset($this->shippingAddresses[$id]);
    }

    public function hasBillingAddress(string $id): bool
    {
        return isset($this->billingAddresses[$id]);
    }

    public function hasCostCenter(string $id): bool
    {
        return isset($this->costCenters[$id]);
    }

    public function hasPaymentTerm(string $id): bool
    {
        return isset($this->paymentTerms[$id]);
    }
}
