<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Shared\Money;

/**
 * Posição de crédito comercial de uma empresa compradora (RN-080).
 *
 *   exposição  = pedidos confirmados não faturados (committed)
 *              + faturas em aberto (receivable)
 *   disponível = limite + exceções vigentes − exposição
 *
 * Cada valor é contado uma única vez: ao faturar, o comprometimento é
 * transferido para recebível na mesma transação (RN-081). Pagamentos só
 * reduzem a exposição quando confirmados (RN-083).
 */
final readonly class CreditPosition
{
    public function __construct(
        public Money $limit,
        public Money $activeExceptions,
        public Money $committed,
        public Money $receivable,
        public bool $blocked,
        public bool $overdueBeyondTolerance,
    ) {}

    public function exposure(): Money
    {
        return $this->committed->plus($this->receivable);
    }

    public function available(): Money
    {
        return $this->limit->plus($this->activeExceptions)->minus($this->exposure());
    }

    public function decide(Money $amount): CreditDecision
    {
        if ($this->blocked) {
            return CreditDecision::Blocked;
        }

        if ($this->overdueBeyondTolerance) {
            return CreditDecision::Overdue;
        }

        return $this->available()->greaterThanOrEqual($amount) ? CreditDecision::Approved : CreditDecision::Insufficient;
    }
}
