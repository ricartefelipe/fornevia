<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Ordering\Portal\CheckoutChoices;
use App\Domain\Buyers\PostalCode;
use App\Enums\Buyers\AddressKind;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Opções de checkout da empresa do comprador: unidades, endereços de
 * entrega e cobrança, centros de custo e condições de pagamento permitidas,
 * apenas ativos. Com uma unidade escolhida, endereços e centros de custo
 * ficam restritos aos da unidade ou sem unidade.
 *
 * A confirmação (PlaceOrder) confere tudo de novo: a lista serve à tela e à
 * validação de campo antes do envio.
 */
final readonly class CheckoutOptions
{
    public function __construct(private PortalBuyer $buyer) {}

    public function handle(?string $unitId = null): CheckoutChoices
    {
        $actor = $this->buyer->require(Permission::PortalCart);
        $companyId = $actor->company->id;

        $units = BuyerUnit::query()
            ->where('buyer_company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $unitId = $unitId !== null && Str::isUuid($unitId) && $units->contains('id', strtolower($unitId)) ? strtolower($unitId) : null;
        $unitNames = $units->pluck('name', 'id')->all();

        $byUnit = static function (Builder $query) use ($unitId): void {
            if ($unitId !== null) {
                $query->where(static fn (Builder $scope): Builder => $scope->whereNull('buyer_unit_id')->orWhere('buyer_unit_id', $unitId));
            }
        };

        $addresses = BuyerAddress::query()
            ->where('buyer_company_id', $companyId)
            ->where('is_active', true)
            ->where($byUnit)
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->orderBy('id')
            ->get();

        $costCenters = CostCenter::query()
            ->where('buyer_company_id', $companyId)
            ->where('is_active', true)
            ->where($byUnit)
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        $allowed = BuyerPaymentTerm::query()->where('buyer_company_id', $companyId)->get();
        $terms = PaymentTerm::query()
            ->whereIn('id', $allowed->pluck('payment_term_id')->all())
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $shipping = $addresses->filter(static fn (BuyerAddress $address): bool => $address->kind === AddressKind::Shipping);
        $billing = $addresses->filter(static fn (BuyerAddress $address): bool => $address->kind === AddressKind::Billing);
        $defaultTerm = $allowed->first(static fn (BuyerPaymentTerm $term): bool => $term->is_default)?->payment_term_id;

        return new CheckoutChoices(
            units: $units->mapWithKeys(static fn (BuyerUnit $unit): array => [$unit->id => "{$unit->name} ({$unit->code})"])->all(),
            shippingAddresses: $shipping->mapWithKeys(static fn (BuyerAddress $address): array => [$address->id => self::addressLabel($address, $unitNames)])->all(),
            billingAddresses: $billing->mapWithKeys(static fn (BuyerAddress $address): array => [$address->id => self::addressLabel($address, $unitNames)])->all(),
            costCenters: $costCenters->mapWithKeys(static fn (CostCenter $center): array => [
                $center->id => "{$center->code} · {$center->name}".($center->buyer_unit_id !== null && isset($unitNames[$center->buyer_unit_id]) ? " ({$unitNames[$center->buyer_unit_id]})" : ''),
            ])->all(),
            paymentTerms: $terms->mapWithKeys(static fn (PaymentTerm $term): array => [$term->id => $term->name])->all(),
            defaultShippingAddressId: $shipping->first(static fn (BuyerAddress $address): bool => $address->is_default)->id ?? $shipping->first()?->id,
            defaultBillingAddressId: $billing->first(static fn (BuyerAddress $address): bool => $address->is_default)?->id,
            defaultPaymentTermId: $defaultTerm !== null && $terms->contains('id', $defaultTerm) ? $defaultTerm : $terms->first()?->id,
        );
    }

    /**
     * @param  array<string, string>  $unitNames
     */
    private static function addressLabel(BuyerAddress $address, array $unitNames): string
    {
        $line = "{$address->label}: {$address->street}, {$address->number}".($address->complement ? " - {$address->complement}" : '')
            ." · {$address->district}, {$address->city}/{$address->state} · CEP ".PostalCode::format($address->postal_code);

        if ($address->buyer_unit_id !== null && isset($unitNames[$address->buyer_unit_id])) {
            $line .= " ({$unitNames[$address->buyer_unit_id]})";
        }

        return $line;
    }
}
