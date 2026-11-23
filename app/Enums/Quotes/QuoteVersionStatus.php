<?php

declare(strict_types=1);

namespace App\Enums\Quotes;

/**
 * Situação de uma versão da proposta. Em preparo: aguardando aprovação comercial ou pronta para envio; com o comprador: enviada.
 */
enum QuoteVersionStatus: string
{
    case PendingApproval = 'pending_approval';
    case Ready = 'ready';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Superseded = 'superseded';
    case Countered = 'countered';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';
    case ApprovalRejected = 'approval_rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Em aprovação comercial',
            self::Ready => 'Pronta para envio',
            self::Sent => 'Enviada',
            self::Accepted => 'Aceita',
            self::Superseded => 'Substituída',
            self::Countered => 'Contraproposta recebida',
            self::Rejected => 'Recusada',
            self::Expired => 'Vencida',
            self::Withdrawn => 'Retirada',
            self::ApprovalRejected => 'Reprovada na aprovação comercial',
        };
    }

    public function isPreparing(): bool
    {
        return $this === self::PendingApproval || $this === self::Ready;
    }

    public function isOpen(): bool
    {
        return $this->isPreparing() || $this === self::Sent;
    }
}
