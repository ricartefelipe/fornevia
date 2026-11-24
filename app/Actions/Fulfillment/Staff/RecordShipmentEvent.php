<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Staff;

use App\Domain\Fulfillment\Exceptions\InvalidShipment;
use App\Domain\Fulfillment\Exceptions\ShipmentNotAllowed;
use App\Domain\Fulfillment\ShipmentEventPolicy;
use App\Enums\Fulfillment\ShipmentEventKind;
use App\Enums\Fulfillment\ShipmentEventSource;
use App\Enums\Fulfillment\ShipmentStatus;
use App\Enums\Identity\Permission;
use App\Models\Fulfillment\ShipmentEvent;
use App\Models\Fulfillment\ShipmentItem;
use App\Models\Ordering\OrderItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Fulfillment\OrderFulfillment;
use App\Services\Fulfillment\ShipmentLocks;
use App\Services\Ordering\OrderConfirmation;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Outbox\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Evento de transporte da remessa expedida (RF-090, RN-091): entrega,
 * acompanhamento ou ocorrência, informado pela equipe ou recebido da
 * transportadora. O identificador externo é único por remessa: o mesmo
 * evento recebido de novo devolve o registro existente sem efeito. A
 * situação só avança; a entrega soma o expedido da remessa ao entregue de
 * cada item.
 *
 * Ordem de locks (ADR-006): pedido → remessa → itens.
 */
final readonly class RecordShipmentEvent
{
    public function __construct(
        private Access $access,
        private ShipmentLocks $locks,
        private OrderFulfillment $fulfillment,
        private OrderConfirmation $orders,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $shipmentId, ShipmentEventKind $kind, ?CarbonImmutable $occurredAt = null, ?string $description = null, ?string $externalId = null, ShipmentEventSource $source = ShipmentEventSource::Staff): ShipmentEvent
    {
        // Equipe ou integração da transportadora: ambas com permissão de expedição.
        $this->access->authorize(Permission::FulfillmentManage);

        $now = $this->clock->now();
        $occurredAt ??= $now;
        $externalId = $externalId !== null ? trim($externalId) : 'equipe-'.Str::uuid7();
        $description = $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, 500) : null;

        if ($externalId === '' || mb_strlen($externalId) > 120) {
            throw new InvalidShipment('Identificador do evento inválido.', ['field' => 'external_id']);
        }

        if ($occurredAt->greaterThan($now->addMinutes(5))) {
            throw new InvalidShipment('A data do evento não pode estar no futuro.', ['field' => 'occurred_at']);
        }

        if ($kind === ShipmentEventKind::Exception && $description === null) {
            throw new InvalidShipment('Descreva a ocorrência.', ['field' => 'description']);
        }

        return DB::transaction(function () use ($shipmentId, $kind, $occurredAt, $description, $externalId, $source): ShipmentEvent {
            [$order, $shipment] = $this->locks->lock($shipmentId);
            $existing = ShipmentEvent::query()->where('shipment_id', $shipment->id)->where('external_id', $externalId)->first();

            if ($existing !== null) {
                return $existing;
            }

            if (! ShipmentEventPolicy::accepts($shipment->status)) {
                throw new ShipmentNotAllowed('A remessa ainda não foi expedida.');
            }

            if ($occurredAt->lessThan($shipment->shipped_at ?? $occurredAt)) {
                throw new InvalidShipment('O evento não pode ser anterior à expedição.', ['field' => 'occurred_at']);
            }

            $next = ShipmentEventPolicy::transition($shipment->status, $kind);
            $event = new ShipmentEvent;
            $event->forceFill([
                'buyer_company_id' => $shipment->buyer_company_id,
                'shipment_id' => $shipment->id,
                'source' => $source,
                'external_id' => $externalId,
                'kind' => $kind,
                'description' => $description,
                'occurred_at' => $occurredAt,
                'applied' => $next !== null,
                'actor_user_id' => $this->access->userId(),
            ])->save();

            $last = $shipment->last_event_at;
            $shipment->forceFill(['last_event_at' => $last === null || $occurredAt->greaterThan($last) ? $occurredAt : $last]);

            if ($next === ShipmentStatus::Delivered) {
                $this->deliver($shipment->id);
                $shipment->forceFill(['status' => ShipmentStatus::Delivered, 'delivered_at' => $occurredAt]);
            }

            $shipment->save();

            if ($next === ShipmentStatus::Delivered) {
                $this->fulfillment->refresh($order);
                $this->orders->event($order, 'order.delivered', ['shipment_id' => $shipment->id, 'number' => $shipment->number]);
                $this->outbox->record('shipment.delivered', 'shipment', $shipment->id, ['shipment_id' => $shipment->id, 'order_id' => $order->id, 'number' => $shipment->number]);
            } elseif ($kind === ShipmentEventKind::Exception) {
                $this->outbox->record('shipment.exception', 'shipment', $shipment->id, ['shipment_id' => $shipment->id, 'order_id' => $order->id, 'number' => $shipment->number, 'description' => $description]);
            }

            $this->audit->record('shipment.event_recorded', $shipment, ['kind' => $kind->value, 'applied' => $next !== null, 'buyer_company_id' => $shipment->buyer_company_id], $description);

            return $event;
        });
    }

    private function deliver(string $shipmentId): void
    {
        $lines = ShipmentItem::query()->where('shipment_id', $shipmentId)->get()->keyBy('order_item_id');
        $items = OrderItem::query()->whereKey($lines->keys()->all())->orderBy('id')->lockForUpdate()->get();

        foreach ($items as $item) {
            $delivered = $lines->get($item->id)?->quantity_checked;

            if ($delivered !== null && $delivered->isPositive()) {
                $item->forceFill(['quantity_delivered' => $item->quantity_delivered->plus($delivered)])->save();
            }
        }
    }
}
