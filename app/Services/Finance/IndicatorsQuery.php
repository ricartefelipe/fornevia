<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Finance\Indicators;
use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;
use App\Enums\Ordering\CommercialStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores do período (RF-102), calculados com as fórmulas de
 * App\Domain\Finance\Indicators e documentadas em docs/ARQUITETURA.md:
 *
 * - ticket médio: pedidos confirmados no período (data de confirmação), sem
 *   os cancelados por inteiro, pela receita líquida do não cancelado;
 * - conversão: versões de proposta enviadas no período e, delas, aceitas;
 * - margem: itens dos pedidos confirmados no período com custo registrado
 *   (receita líquida da quantidade não cancelada);
 * - inadimplência: parcelas com vencimento no período; vencidas em aberto
 *   são as que, hoje, passaram do vencimento sem quitação.
 *
 * Valores na moeda do pedido, em centavos; período em datas do fuso
 * comercial, inclusivas.
 */
final readonly class IndicatorsQuery
{
    /**
     * @return array{orders: int, revenue: Money, average_ticket: Money|null, proposals: int, accepted: int, conversion: Percentage|null, margin: Percentage|null, margin_revenue: Money, due: Money, overdue: Money, delinquency: Percentage|null}
     */
    public function for(CarbonImmutable $from, CarbonImmutable $until, CarbonImmutable $today): array
    {
        $tz = (string) config('app.business_timezone');
        $start = $from->setTimezone($tz)->startOfDay()->utc();
        $end = $until->setTimezone($tz)->endOfDay()->utc();
        // Pedidos confirmados no período que não foram cancelados por inteiro,
        // pela receita líquida do que não foi cancelado (itens e frete).
        $confirmed = [CommercialStatus::Confirmed->value, CommercialStatus::PartiallyCancelled->value];

        $orders = (object) (array) DB::query()->fromSub(DB::table('orders')
            ->whereNotNull('confirmed_at')->whereBetween('confirmed_at', [$start, $end])->whereIn('commercial_status', $confirmed)
            ->selectRaw('orders.freight_cents + coalesce((select sum(round(i.unit_net_price_cents * (i.quantity - i.quantity_cancelled))) from order_items i where i.order_id = orders.id), 0) as net'), 'o')
            ->selectRaw('count(*) as orders, coalesce(sum(net), 0) as revenue')->first();

        $proposals = (object) (array) DB::table('quote_versions')->whereNotNull('sent_at')->whereBetween('sent_at', [$start, $end])
            ->selectRaw("count(*) as sent, count(*) filter (where status = 'accepted') as accepted")->first();

        $margin = (object) (array) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('order_item_costs', 'order_item_costs.order_item_id', '=', 'order_items.id')
            ->whereNotNull('order_item_costs.unit_cost_cents')
            ->whereNotNull('orders.confirmed_at')
            ->whereBetween('orders.confirmed_at', [$start, $end])
            ->whereIn('orders.commercial_status', $confirmed)
            ->selectRaw('coalesce(sum(round(order_items.unit_net_price_cents * (order_items.quantity - order_items.quantity_cancelled))), 0) as revenue,
                         coalesce(sum(round(order_item_costs.unit_cost_cents * (order_items.quantity - order_items.quantity_cancelled))), 0) as cost')
            ->first();

        $installments = (object) (array) DB::table('receivable_installments')
            ->whereBetween('due_date', [$from->toDateString(), $until->toDateString()])
            ->selectRaw("coalesce(sum(amount_cents + adjusted_cents), 0) as due,
                         coalesce(sum(case when status = 'open' and due_date < ? then amount_cents + adjusted_cents - paid_cents - credited_cents else 0 end), 0) as overdue", [$today->toDateString()])
            ->first();

        $revenue = Money::ofCents((int) $orders->revenue);
        $marginRevenue = Money::ofCents((int) $margin->revenue);
        $due = Money::ofCents((int) $installments->due);
        $overdue = Money::ofCents((int) $installments->overdue);

        return [
            'orders' => (int) $orders->orders,
            'revenue' => $revenue,
            'average_ticket' => Indicators::averageTicket($revenue, (int) $orders->orders),
            'proposals' => (int) $proposals->sent,
            'accepted' => (int) $proposals->accepted,
            'conversion' => Indicators::ratio((int) $proposals->accepted, (int) $proposals->sent),
            'margin' => Indicators::margin($marginRevenue, Money::ofCents((int) $margin->cost)),
            'margin_revenue' => $marginRevenue,
            'due' => $due,
            'overdue' => $overdue,
            'delinquency' => $due->isPositive() ? Indicators::ratio($overdue->cents, $due->cents) : null,
        ];
    }
}
