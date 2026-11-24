<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Fulfillment\Staff\RecordShipmentEvent;
use App\Enums\Fulfillment\ShipmentEventKind;
use App\Enums\Fulfillment\ShipmentEventSource;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use App\Models\Finance\Invoice;
use App\Models\Fulfillment\Shipment;
use App\Models\Inventory\StockLevel;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use App\Support\Integrations\Iso8601;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Recursos da API v1 (RF-110), no contexto de integração do tenant do
 * token: cada operação exige a permissão do cliente e devolve só campos
 * públicos (sem custos, margens, políticas ou dados de outra empresa
 * vazados por relação). Datas em ISO 8601 (UTC), valores em centavos,
 * quantidades como texto decimal.
 */
final readonly class ApiResources
{
    public function __construct(private Access $access, private RecordShipmentEvent $events) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function orders(array $query): array
    {
        $this->access->authorize(Permission::OrdersView);
        $filters = ApiListing::filters($query, [
            'commercial_status' => 'enum:'.implode('|', array_map(static fn (CommercialStatus $s): string => $s->value, CommercialStatus::cases())),
            'buyer_company_id' => 'uuid',
            'updated_since' => 'datetime',
        ]);

        $orders = Order::query()
            ->when(isset($filters['commercial_status']), static fn ($q) => $q->where('commercial_status', $filters['commercial_status']))
            ->when(isset($filters['buyer_company_id']), static fn ($q) => $q->where('buyer_company_id', $filters['buyer_company_id']))
            ->when(isset($filters['updated_since']), static fn ($q) => $q->where('updated_at', '>=', Iso8601::parse($filters['updated_since'])))
            ->orderBy('created_at')->orderBy('id');

        return self::page(ApiListing::paginate($orders, $query), fn (Order $order): array => $this->order($order, false));
    }

    /**
     * @return array<string, mixed>
     */
    public function order(Order|string $order, bool $items = true): array
    {
        $this->access->authorize(Permission::OrdersView);
        $order = $order instanceof Order ? $order : Order::query()->findOrFail(UntrustedId::uuid($order, Order::class));

        $data = [
            'id' => $order->id,
            'number' => $order->number,
            'buyer_company_id' => $order->buyer_company_id,
            'source' => $order->source->value,
            'commercial_status' => $order->commercial_status->value,
            'approval_status' => $order->approval_status->value,
            'fulfillment_status' => $order->fulfillment_status->value,
            'financial_status' => $order->financial_status->value,
            'purchase_order_reference' => $order->purchase_order_reference,
            'subtotal_cents' => $order->subtotal_cents->cents,
            'discount_cents' => $order->discount_cents->cents,
            'freight_cents' => $order->freight_cents->cents,
            'total_cents' => $order->total_cents->cents,
            'payment_term' => ['code' => $order->payment_term['code'] ?? null, 'name' => $order->payment_term['name'] ?? null],
            'shipping_address' => array_intersect_key((array) $order->shipping_address, array_flip(['street', 'number', 'complement', 'district', 'city', 'state', 'postal_code'])),
            'created_at' => $order->created_at->utc()->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->utc()->toIso8601String(),
            'updated_at' => $order->updated_at?->utc()->toIso8601String(),
        ];

        if ($items) {
            $data['items'] = array_values(OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->get()->map(static fn (OrderItem $i): array => [
                'id' => $i->id, 'line_number' => $i->line_number, 'sku_id' => $i->sku_id, 'sku_code' => $i->sku_code, 'product_name' => $i->product_name,
                'unit_code' => $i->unit_code, 'quantity' => $i->quantity->toDatabase(), 'unit_net_price_cents' => $i->unit_net_price_cents->cents,
                'line_total_cents' => $i->line_total_cents->cents, 'quantity_cancelled' => $i->quantity_cancelled->toDatabase(),
                'quantity_shipped' => $i->quantity_shipped->toDatabase(), 'quantity_delivered' => $i->quantity_delivered->toDatabase(),
            ])->all());
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function buyers(array $query): array
    {
        $this->access->authorize(Permission::BuyersView);
        $filters = ApiListing::filters($query, ['document' => 'string', 'updated_since' => 'datetime']);

        $buyers = BuyerCompany::query()
            ->when(isset($filters['document']), static fn ($q) => $q->where('document', preg_replace('/\D/', '', $filters['document'])))
            ->when(isset($filters['updated_since']), static fn ($q) => $q->where('updated_at', '>=', Iso8601::parse($filters['updated_since'])))
            ->orderBy('trade_name')->orderBy('id');

        return self::page(ApiListing::paginate($buyers, $query), static fn (BuyerCompany $b): array => [
            'id' => $b->id, 'legal_name' => $b->legal_name, 'trade_name' => $b->trade_name, 'document' => $b->document,
            'email' => $b->email, 'phone' => $b->phone, 'status' => $b->status->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function skus(array $query): array
    {
        $this->access->authorize(Permission::CatalogView);
        $filters = ApiListing::filters($query, ['code' => 'string', 'status' => 'enum:active|inactive|archived']);

        $skus = Sku::query()->with(['product', 'salesUnit'])
            ->when(isset($filters['code']), static fn ($q) => $q->where('code', $filters['code']))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('code')->orderBy('id');

        return self::page(ApiListing::paginate($skus, $query), static fn (Sku $s): array => [
            'id' => $s->id, 'code' => $s->code, 'gtin' => $s->gtin, 'product_id' => $s->product_id, 'product_name' => $s->product->name,
            'variant_label' => $s->variant_label, 'sales_unit' => $s->salesUnit->code, 'conversion_factor' => (string) $s->conversion_factor,
            'min_order_quantity' => $s->min_order_quantity->toDatabase(), 'order_multiple' => $s->order_multiple->toDatabase(), 'status' => $s->status->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function stock(array $query): array
    {
        $this->access->authorize(Permission::InventoryView);
        $filters = ApiListing::filters($query, ['sku_id' => 'uuid', 'warehouse_id' => 'uuid']);

        $levels = StockLevel::query()
            ->when(isset($filters['sku_id']), static fn ($q) => $q->where('sku_id', $filters['sku_id']))
            ->when(isset($filters['warehouse_id']), static fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->orderBy('warehouse_id')->orderBy('sku_id');

        return self::page(ApiListing::paginate($levels, $query), static fn (StockLevel $l): array => [
            'warehouse_id' => $l->warehouse_id, 'sku_id' => $l->sku_id, 'on_hand' => $l->on_hand->toDatabase(),
            'reserved' => $l->reserved->toDatabase(), 'available' => $l->on_hand->minus($l->reserved)->toDatabase(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function invoices(array $query): array
    {
        $this->access->authorize(Permission::FinanceView);
        $filters = ApiListing::filters($query, ['status' => 'enum:open|paid', 'order_id' => 'uuid', 'buyer_company_id' => 'uuid']);

        $invoices = Invoice::query()->with('installments')
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['order_id']), static fn ($q) => $q->where('order_id', $filters['order_id']))
            ->when(isset($filters['buyer_company_id']), static fn ($q) => $q->where('buyer_company_id', $filters['buyer_company_id']))
            ->orderBy('issue_date')->orderBy('id');

        return self::page(ApiListing::paginate($invoices, $query), static fn (Invoice $i): array => [
            'id' => $i->id, 'number' => $i->number, 'order_id' => $i->order_id, 'buyer_company_id' => $i->buyer_company_id,
            'status' => $i->status->value, 'issue_date' => $i->issue_date->toDateString(), 'total_cents' => $i->total_cents->cents,
            'paid_cents' => $i->paid_cents->cents, 'credited_cents' => $i->credited_cents->cents, 'adjusted_cents' => $i->adjusted_cents->cents,
            'installments' => array_values($i->installments->map(static fn ($p): array => [
                'sequence' => $p->sequence, 'due_date' => $p->due_date->toDateString(), 'amount_cents' => $p->amount_cents->plus($p->adjusted_cents)->cents,
                'paid_cents' => $p->paid_cents->cents, 'credited_cents' => $p->credited_cents->cents, 'status' => $p->status->value,
            ])->all()),
        ]);
    }

    /**
     * Evento da transportadora (RF-090, RN-091): o identificador externo é
     * obrigatório e único por remessa; o mesmo evento de novo devolve o
     * registro existente.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function shipmentEvent(string $shipmentId, array $input): array
    {
        $this->access->authorize(Permission::FulfillmentManage);
        $errors = [];
        $externalId = $input['external_id'] ?? null;
        $kind = is_string($input['kind'] ?? null) ? ShipmentEventKind::tryFrom($input['kind']) : null;
        $occurred = $input['occurred_at'] ?? null;
        $description = $input['description'] ?? null;

        if (! is_string($externalId) || trim($externalId) === '' || mb_strlen($externalId) > 120) {
            $errors['external_id'] = ['Informe o identificador do evento na transportadora (até 120 caracteres).'];
        }

        if ($kind === null) {
            $errors['kind'] = ['Use in_transit, out_for_delivery, delivered ou exception.'];
        }

        $occurredAt = Iso8601::parse($occurred);

        if ($occurredAt === null) {
            $errors['occurred_at'] = ['Informe a data e hora do evento em ISO 8601, com fuso (ex.: 2026-11-24T10:30:00-03:00).'];
        }

        if ($description !== null && (! is_string($description) || mb_strlen($description) > 500)) {
            $errors['description'] = ['A descrição deve ter até 500 caracteres.'];
        }

        if ($errors !== [] || $kind === null || ! is_string($externalId) || $occurredAt === null) {
            throw ValidationException::withMessages($errors);
        }

        Shipment::query()->findOrFail(UntrustedId::uuid($shipmentId, Shipment::class));
        $event = $this->events->handle($shipmentId, $kind, $occurredAt, is_string($description) ? $description : null, trim($externalId), ShipmentEventSource::Carrier);

        return [
            'id' => $event->id, 'shipment_id' => $event->shipment_id, 'external_id' => $event->external_id, 'kind' => $event->kind->value,
            'occurred_at' => $event->occurred_at->utc()->toIso8601String(), 'applied' => $event->applied,
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $page
     * @param  callable(TModel): array<string, mixed>  $map
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    private static function page(LengthAwarePaginator $page, callable $map): array
    {
        return [
            'data' => array_values(array_map($map, $page->items())),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }
}
