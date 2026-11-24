<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;

/**
 * Indicadores comerciais e financeiros (RF-102), com as fórmulas
 * documentadas em docs/ARQUITETURA.md:
 *
 *   ticket médio   = soma dos totais dos pedidos confirmados ÷ quantidade
 *   conversão      = cotações aceitas ÷ cotações com proposta enviada
 *   margem         = (receita líquida − custo) ÷ receita líquida, nos itens com custo
 *   inadimplência  = parcelas vencidas em aberto ÷ total das parcelas vencidas
 *
 * Sem base (divisor zero), o indicador é indefinido (null).
 */
final class Indicators
{
    public static function averageTicket(Money $total, int $orders): ?Money
    {
        return $orders > 0 ? Money::ofCents(intdiv($total->cents, $orders)) : null;
    }

    public static function ratio(int $part, int $whole): ?Percentage
    {
        if ($whole <= 0) {
            return null;
        }

        return Percentage::ofBasisPoints((int) round(max(0, min($part, $whole)) * 10_000 / $whole));
    }

    /** Margem pode ser negativa (venda abaixo do custo). */
    public static function margin(Money $revenue, Money $cost): ?Percentage
    {
        if (! $revenue->isPositive()) {
            return null;
        }

        return Percentage::ofBasisPoints((int) round(($revenue->cents - $cost->cents) * 10_000 / $revenue->cents), allowOutOfRange: true);
    }
}
