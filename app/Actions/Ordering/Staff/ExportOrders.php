<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Staff;

use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Domain\Shared\Cnpj;
use App\Enums\Identity\Permission;
use App\Models\Ordering\Order;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Integrations\Csv;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exportação CSV dos pedidos filtrados (RF-113, RS-110): separador ";",
 * BOM para abrir acentuado em planilhas, datas no fuso comercial, valores
 * com vírgula decimal e textos neutralizados contra fórmulas. O conteúdo é
 * montado dentro da requisição, no contexto do tenant e da carteira, e a
 * exportação fica na auditoria.
 */
final readonly class ExportOrders
{
    public const array HEADER = ['numero', 'data', 'empresa', 'cnpj', 'ordem_de_compra', 'situacao', 'aprovacao', 'financeiro', 'atendimento', 'total'];

    public function __construct(private Access $access, private AuditRecorder $audit) {}

    /**
     * @param  Builder<Order>  $orders  consulta já filtrada (StaffOrderQuery)
     * @param  array<string, string>  $filters  filtros aplicados, para a auditoria
     */
    public function handle(Builder $orders, array $filters): string
    {
        $this->access->authorize(Permission::OrdersView);
        $count = (clone $orders)->count();
        $max = (int) config('integrations.exports.max_rows');

        if ($count > $max) {
            throw new InvalidIntegration('A exportação aceita até '.number_format($max, 0, ',', '.').' pedidos; refine os filtros.');
        }

        $timezone = (string) config('app.business_timezone');
        $csv = "\u{FEFF}".Csv::line(self::HEADER);

        // Percorre por id (UUIDv7, em ordem de criação), em lotes.
        foreach ($orders->reorder()->with('buyerCompany')->lazyById(500, 'id') as $order) {
            $company = $order->buyerCompany;
            $csv .= Csv::line([
                $order->number,
                $order->created_at->setTimezone($timezone)->format('d/m/Y H:i'),
                $company?->trade_name,
                $company !== null ? Cnpj::format($company->document) : null,
                $order->purchase_order_reference,
                $order->commercial_status->label(),
                $order->approval_status->label(),
                $order->financial_status->label(),
                $order->fulfillment_status->label(),
                str_replace('.', ',', $order->total_cents->toDecimalString()),
            ]);
        }

        $this->audit->record('orders.exported', null, ['rows' => $count, 'filters' => array_filter($filters, static fn (string $v): bool => $v !== '')]);

        return $csv;
    }
}
