<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Shared\Money;

/**
 * Reembolso concorrente (RN-101): a soma dos reembolsos pendentes e
 * efetivados nunca passa do valor pago confirmado.
 */
final class RefundLimit
{
    public static function available(Money $paid, Money $refunded, Money $pending): Money
    {
        return Money::max(Money::zero(), $paid->minus($refunded)->minus($pending));
    }
}
