<?php

declare(strict_types=1);

namespace App\Enums\Finance;

/**
 * Situação de um reembolso consultada no gateway (conferência dos
 * reembolsos em verificação).
 */
enum RefundOutcome: string
{
    case Done = 'done';
    case Failed = 'failed';
    case Pending = 'pending';
    case Missing = 'missing';
}
