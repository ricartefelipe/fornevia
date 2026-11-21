<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Shared\Money;

/**
 * Orçamento de um centro de custo no mês (RN-055):
 * disponível = valor − reservado − consumido.
 */
final readonly class BudgetPosition
{
    public function __construct(
        public Money $amount,
        public Money $reserved,
        public Money $consumed,
    ) {}

    public function available(): Money
    {
        return $this->amount->minus($this->reserved)->minus($this->consumed);
    }

    public function fits(Money $additional): bool
    {
        return $this->available()->greaterThanOrEqual($additional);
    }
}
