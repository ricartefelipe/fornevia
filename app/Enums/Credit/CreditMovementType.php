<?php

declare(strict_types=1);

namespace App\Enums\Credit;

enum CreditMovementType: string
{
    case OrderCommitment = 'order_commitment';
    case CommitmentRelease = 'commitment_release';
    case Invoicing = 'invoicing';
    case PaymentSettlement = 'payment_settlement';
    case CreditNote = 'credit_note';
    case ReceivableAdjustment = 'receivable_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::OrderCommitment => 'Comprometimento de pedido',
            self::CommitmentRelease => 'Liberação de comprometimento',
            self::Invoicing => 'Faturamento',
            self::PaymentSettlement => 'Pagamento confirmado',
            self::CreditNote => 'Crédito ao cliente',
            self::ReceivableAdjustment => 'Ajuste de recebível',
        };
    }
}
