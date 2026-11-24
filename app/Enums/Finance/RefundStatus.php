<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Em processamento',
            self::Done => 'Efetivado',
            self::Failed => 'Falhou',
        };
    }
}
