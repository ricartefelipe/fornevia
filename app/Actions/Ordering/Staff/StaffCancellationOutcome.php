<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Staff;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\Ordering\Order;

/**
 * Resultado de um cancelamento feito pela equipe: o que foi cancelado por
 * item (unidade de venda), quanto de reserva e de encomenda deixou de
 * existir (unidade base) e o crédito comprometido liberado.
 */
final readonly class StaffCancellationOutcome
{
    /**
     * @param  list<array{sku_code: string, unit_code: string, base_unit_code: string, cancelled: Quantity, reservation_released: Quantity, backorder_released: Quantity}>  $lines
     */
    public function __construct(
        public Order $order,
        public array $lines,
        public Money $creditReleased,
        public bool $fullyCancelled,
    ) {}

    /**
     * Resumo para a tela, em linhas.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        $summary = [];

        foreach ($this->lines as $line) {
            $text = $line['sku_code'].': '.$line['cancelled']->format().' '.$line['unit_code'].' cancelado(s)';

            if ($line['reservation_released']->isPositive()) {
                $text .= ' · '.$line['reservation_released']->format().' '.$line['base_unit_code'].' liberado(s) da reserva';
            }

            if ($line['backorder_released']->isPositive()) {
                $text .= ' · '.$line['backorder_released']->format().' '.$line['base_unit_code'].' retirado(s) da encomenda';
            }

            $summary[] = $text.'.';
        }

        $summary[] = $this->creditReleased->isPositive()
            ? 'Crédito comercial liberado: '.$this->creditReleased->format().'.'
            : 'Nenhum crédito comprometido a liberar.';

        return $summary;
    }
}
