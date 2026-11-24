<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Shared\Money;
use App\Enums\Ordering\FinancialStatus;

/**
 * Situação financeira do pedido: faturado em relação ao que é devido
 * (total menos o cancelado) e quitado em relação ao faturado.
 */
final class FinancialProgress
{
    public static function of(Money $due, Money $invoiced, Money $settled): FinancialStatus
    {
        if (! $invoiced->isPositive()) {
            return FinancialStatus::NotInvoiced;
        }

        $fullyInvoiced = $invoiced->greaterThanOrEqual($due);

        if ($fullyInvoiced && $settled->greaterThanOrEqual($invoiced)) {
            return FinancialStatus::Paid;
        }

        if ($settled->isPositive()) {
            return FinancialStatus::PartiallyPaid;
        }

        return $fullyInvoiced ? FinancialStatus::Invoiced : FinancialStatus::PartiallyInvoiced;
    }
}
