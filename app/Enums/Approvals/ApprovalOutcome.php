<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

/**
 * Resultado de uma decisão ou de uma tentativa de confirmação após a
 * aprovação interna.
 */
enum ApprovalOutcome: string
{
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Confirmed = 'confirmed';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Invalidated = 'invalidated';

    public function message(): string
    {
        return match ($this) {
            self::Pending => 'Decisão registrada. O pedido segue para os próximos aprovadores.',
            self::Rejected => 'Pedido rejeitado. O orçamento reservado foi liberado.',
            self::Confirmed => 'Pedido aprovado e confirmado com o distribuidor.',
            self::AwaitingConfirmation => 'Pedido aprovado, mas ainda não confirmado com o distribuidor. Veja o motivo no pedido.',
            self::Invalidated => 'Preços ou condições mudaram desde a submissão: as aprovações foram invalidadas e o pedido voltou para aprovação.',
        };
    }
}
