<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Shared\Money;

/**
 * Totais do documento: soma das linhas, sem rateio implícito; frete é
 * parcela explícita.
 */
final readonly class DocumentTotals
{
    private function __construct(
        public Money $subtotal,
        public Money $discount,
        public Money $freight,
        public Money $total,
    ) {}

    /**
     * @param  list<LineAmounts>  $lines
     */
    public static function of(array $lines, ?Money $freight = null): self
    {
        $freight ??= Money::zero();
        $gross = Money::sum(array_map(static fn (LineAmounts $l): Money => $l->gross, $lines));
        $net = Money::sum(array_map(static fn (LineAmounts $l): Money => $l->total, $lines));

        return new self($gross, $gross->minus($net), $freight, $net->plus($freight));
    }

    /** Mesmos itens com o frete informado no lugar do atual. */
    public function withFreight(Money $freight): self
    {
        $net = $this->subtotal->minus($this->discount);

        return new self($this->subtotal, $this->discount, $freight, $net->plus($freight));
    }

    /** Subtotal líquido dos itens (sem frete), base da isenção de frete. */
    public function itemsNet(): Money
    {
        return $this->subtotal->minus($this->discount);
    }
}
