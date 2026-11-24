<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Verifying = 'verifying';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Em processamento',
            self::Verifying => 'Em verificação no gateway',
            self::Done => 'Efetivado',
            self::Failed => 'Falhou',
        };
    }
}
