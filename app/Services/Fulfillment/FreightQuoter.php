<?php

declare(strict_types=1);

namespace App\Services\Fulfillment;

use App\Domain\Fulfillment\FreightCalculator;
use App\Domain\Fulfillment\FreightQuote;
use App\Domain\Shared\Money;
use App\Models\Buyers\BuyerCompany;
use App\Models\Fulfillment\BuyerFreightCondition;
use App\Models\Fulfillment\FreightRule;

/**
 * Frete do distribuidor para o pedido (RF-092, ADR-027): condição negociada
 * da empresa ou regra ativa pela UF do endereço de entrega. Calculado no
 * servidor; a tela só exibe.
 */
final class FreightQuoter
{
    public function quote(BuyerCompany $buyer, ?string $state, Money $itemsNet): FreightQuote
    {
        $condition = BuyerFreightCondition::query()->where('buyer_company_id', $buyer->id)->first();
        $rules = FreightRule::query()->where('is_active', true)->orderByDesc('priority')->orderBy('id')->get();

        return FreightCalculator::calculate(
            $condition?->kind,
            $condition !== null ? $condition->amount_cents : Money::zero(),
            array_values($rules->map(static fn (FreightRule $rule) => $rule->view())->all()),
            $state,
            $itemsNet,
        );
    }
}
