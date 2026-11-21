<?php

declare(strict_types=1);

namespace App\Enums\Ordering;

enum FinancialStatus: string
{
    case NotInvoiced = 'not_invoiced';
    case PartiallyInvoiced = 'partially_invoiced';
    case Invoiced = 'invoiced';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::NotInvoiced => 'Não faturado',
            self::PartiallyInvoiced => 'Faturado parcialmente',
            self::Invoiced => 'Faturado',
            self::PartiallyPaid => 'Pago parcialmente',
            self::Paid => 'Pago',
        };
    }
}
