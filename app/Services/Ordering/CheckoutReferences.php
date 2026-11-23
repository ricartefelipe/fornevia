<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Data\Ordering\CheckoutSelection;
use App\Domain\Ordering\Exceptions\InvalidCheckout;
use App\Enums\Buyers\AddressKind;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use App\Support\Http\UntrustedId;

/**
 * Escolhas do checkout para uma empresa: endereço de entrega e de cobrança
 * ativos, condição de pagamento permitida à empresa e ativa, unidade e
 * centro de custo ativos. Os identificadores vêm do cliente (não
 * confiáveis) e as consultas filtram a empresa explicitamente: também
 * rodam no contexto de sistema.
 */
final class CheckoutReferences
{
    public function resolve(
        BuyerCompany $buyer,
        string $shippingAddressId,
        ?string $billingAddressId,
        string $paymentTermId,
        ?string $buyerUnitId,
        ?string $costCenterId,
    ): CheckoutSelection {
        $shipping = BuyerAddress::query()
            ->where('buyer_company_id', $buyer->id)
            ->where('is_active', true)
            ->where('kind', AddressKind::Shipping->value)
            ->find(UntrustedId::uuid($shippingAddressId, BuyerAddress::class))
            ?? throw new InvalidCheckout('Endereço de entrega inválido para esta empresa.');

        $billing = $billingAddressId === null ? null : (BuyerAddress::query()
            ->where('buyer_company_id', $buyer->id)
            ->where('is_active', true)
            ->where('kind', AddressKind::Billing->value)
            ->find(UntrustedId::uuid($billingAddressId, BuyerAddress::class))
            ?? throw new InvalidCheckout('Endereço de cobrança inválido para esta empresa.'));

        $term = $this->allowedTerm($buyer, $paymentTermId)
            ?? throw new InvalidCheckout('Condição de pagamento não disponível para esta empresa.');

        $unitId = null;

        if ($buyerUnitId !== null) {
            $unit = BuyerUnit::query()
                ->where('buyer_company_id', $buyer->id)->where('is_active', true)
                ->whereKey(UntrustedId::uuid($buyerUnitId, BuyerUnit::class))
                ->first() ?? throw new InvalidCheckout('Unidade inválida para esta empresa.');
            $unitId = $unit->id;
        }

        $centerId = null;

        if ($costCenterId !== null) {
            $center = CostCenter::query()
                ->where('buyer_company_id', $buyer->id)->where('is_active', true)
                ->whereKey(UntrustedId::uuid($costCenterId, CostCenter::class))
                ->first() ?? throw new InvalidCheckout('Centro de custo inválido para esta empresa.');
            $centerId = $center->id;
        }

        return new CheckoutSelection($shipping, $billing, $term, $unitId, $centerId);
    }

    /**
     * Condição permitida à empresa e ativa; null quando não está.
     */
    public function allowedTerm(BuyerCompany $buyer, string $paymentTermId): ?PaymentTerm
    {
        $termId = UntrustedId::uuid($paymentTermId, PaymentTerm::class);
        $allowed = BuyerPaymentTerm::query()
            ->where('buyer_company_id', $buyer->id)
            ->where('payment_term_id', $termId)
            ->exists();

        return $allowed ? PaymentTerm::query()->where('is_active', true)->find($termId) : null;
    }

    /**
     * Retrato da condição de pagamento guardado nos documentos.
     *
     * @return array{code: string, name: string, installment_days: list<int>, requires_credit: bool}
     */
    public static function termSnapshot(PaymentTerm $term): array
    {
        return [
            'code' => $term->code,
            'name' => $term->name,
            'installment_days' => $term->installment_days,
            'requires_credit' => $term->requires_credit,
        ];
    }

    /**
     * A definição da condição mudou desde o retrato (código, prazos ou
     * exigência de crédito)?
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function termChanged(?array $snapshot, PaymentTerm $term): bool
    {
        return ($snapshot['code'] ?? null) !== $term->code
            || ($snapshot['installment_days'] ?? null) !== $term->installment_days
            || (bool) ($snapshot['requires_credit'] ?? false) !== $term->requires_credit;
    }
}
