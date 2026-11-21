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
}
