<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum FinanceEntryType: string
{
    case InvoiceIssued = 'invoice_issued';
    case Payment = 'payment';
    case Fee = 'fee';
    case Refund = 'refund';
    case CreditNote = 'credit_note';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::InvoiceIssued => 'Fatura emitida',
            self::Payment => 'Pagamento',
            self::Fee => 'Taxa do gateway',
            self::Refund => 'Reembolso',
            self::CreditNote => 'Crédito concedido',
            self::Adjustment => 'Ajuste',
        };
    }
}
