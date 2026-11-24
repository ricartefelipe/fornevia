<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Domain\Shared\Money;
use App\Enums\Fulfillment\FreightConditionKind;

/**
 * Frete do pedido (RF-092): a condição negociada com a empresa prevalece;
 * sem ela, vale a regra ativa de maior prioridade que atende a UF do
 * endereço de entrega (empate: a de menor valor), isenta quando o subtotal
 * líquido alcança o mínimo da regra; sem regra, frete zero.
 */
final class FreightCalculator
{
    /**
     * @param  list<FreightRuleView>  $rules  regras ativas
     */
    public static function calculate(?FreightConditionKind $negotiated, Money $negotiatedAmount, array $rules, ?string $state, Money $subtotal): FreightQuote
    {
        if ($negotiated === FreightConditionKind::Free) {
            return new FreightQuote(Money::zero(), 'Frete isento negociado');
        }

        if ($negotiated === FreightConditionKind::Fixed) {
            return new FreightQuote($negotiatedAmount, 'Frete negociado');
        }

        $candidates = array_values(array_filter($rules, static fn (FreightRuleView $rule): bool => $rule->serves($state)));

        if ($candidates === []) {
            return new FreightQuote(Money::zero(), 'Sem frete cadastrado para a região');
        }

        usort($candidates, static fn (FreightRuleView $a, FreightRuleView $b): int => [$b->priority, $a->amount->cents, $a->id] <=> [$a->priority, $b->amount->cents, $b->id]);
        $rule = $candidates[0];

        if ($rule->freeFrom !== null && $subtotal->greaterThanOrEqual($rule->freeFrom)) {
            return new FreightQuote(Money::zero(), "Frete isento ({$rule->name})", $rule->id);
        }

        return new FreightQuote($rule->amount, $rule->name, $rule->id);
    }
}
