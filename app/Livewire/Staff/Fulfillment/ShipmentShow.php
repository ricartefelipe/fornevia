<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Fulfillment;

use App\Actions\Fulfillment\Staff\CancelShipment;
use App\Actions\Fulfillment\Staff\CheckShipment;
use App\Actions\Fulfillment\Staff\RecordShipmentEvent;
use App\Actions\Fulfillment\Staff\ShipShipment;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Fulfillment\ShipmentEventKind;
use App\Enums\Identity\Permission;
use App\Models\Fulfillment\Shipment;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Remessa: conferência por item, expedição com transportadora e
 * rastreamento, cancelamento da separação e eventos de transporte
 * (entrega e ocorrências), com o histórico.
 */
#[Layout('layouts.app')]
#[Title('Remessa')]
final class ShipmentShow extends Component
{
    #[Locked]
    public string $shipmentId = '';

    /** Identificador do próximo evento informado nesta tela: o reenvio não duplica. */
    #[Locked]
    public string $eventKey = '';

    /** @var array<string, string> shipment_item_id => quantidade conferida */
    public array $checked = [];

    public string $carrier = '';

    public string $trackingCode = '';

    public string $cancelReason = '';

    public string $eventKind = 'delivered';

    public string $eventDescription = '';

    public string $eventAt = '';

    public string $message = '';

    public string $failure = '';

    public function mount(string $shipment): void
    {
        $this->shipmentId = Shipment::query()->findOrFail(UntrustedId::uuid($shipment, Shipment::class))->id;
        $this->eventKey = 'equipe-'.Str::uuid7();
    }

    public function check(CheckShipment $check): void
    {
        $this->attempt(fn () => $check->handle($this->shipmentId, $this->checked), 'Conferência registrada. A remessa pode ser expedida.');
    }

    public function ship(ShipShipment $ship): void
    {
        $this->attempt(fn () => $ship->handle($this->shipmentId, $this->carrier, $this->trackingCode), 'Remessa expedida. O estoque reservado foi baixado.', ['carrier' => 'carrier', 'tracking_code' => 'trackingCode']);
    }

    public function cancel(CancelShipment $cancel): void
    {
        $this->attempt(fn () => $cancel->handle($this->shipmentId, $this->cancelReason), 'Separação cancelada. As quantidades voltaram a faltar separar.', ['reason' => 'cancelReason']);
    }

    public function recordEvent(RecordShipmentEvent $record): void
    {
        $kind = ShipmentEventKind::tryFrom($this->eventKind);

        if ($kind === null) {
            $this->addError('eventKind', 'Escolha o tipo do evento.');

            return;
        }

        $at = null;

        if (trim($this->eventAt) !== '') {
            try {
                $at = CarbonImmutable::parse($this->eventAt, (string) config('app.business_timezone'))->utc();
            } catch (Throwable) {
                $this->addError('eventAt', 'Data e hora inválidas.');

                return;
            }
        }

        $done = $this->attempt(fn () => $record->handle($this->shipmentId, $kind, $at, $this->eventDescription, $this->eventKey), 'Evento registrado.', ['description' => 'eventDescription', 'occurred_at' => 'eventAt']);

        if ($done) {
            $this->eventKey = 'equipe-'.Str::uuid7();
            $this->reset('eventDescription', 'eventAt');
        }
    }

    public function render(Access $access): View
    {
        $shipment = Shipment::query()->with(['items.orderItem', 'events', 'order.buyerCompany', 'warehouse'])->findOrFail($this->shipmentId);

        foreach ($shipment->items as $item) {
            $this->checked[$item->id] ??= ($item->quantity_checked ?? $item->quantity)->format();
        }

        return view('livewire.staff.fulfillment.shipment-show', [
            'shipment' => $shipment,
            'canManage' => $access->can(Permission::FulfillmentManage),
            'kinds' => collect(ShipmentEventKind::cases())->mapWithKeys(fn (ShipmentEventKind $k): array => [$k->value => $k->label()])->all(),
            'timezone' => (string) config('app.business_timezone'),
        ])->title("Remessa {$shipment->number}");
    }

    /**
     * @param  Closure(): mixed  $action
     * @param  array<string, string>  $fields  campo da regra => propriedade da tela
     */
    private function attempt(Closure $action, string $success, array $fields = []): bool
    {
        $this->resetErrorBag();
        $this->message = '';
        $this->failure = '';

        try {
            $action();
        } catch (BusinessRuleViolation $e) {
            $field = $e->details()['field'] ?? null;

            if (is_string($field) && isset($fields[$field])) {
                $this->addError($fields[$field], $e->getMessage());
            } elseif (is_string($field) && str_starts_with($field, 'checked.')) {
                $this->addError($field, $e->getMessage());
            } else {
                $this->failure = $e->getMessage();
            }

            return false;
        }

        $this->message = $success;

        return true;
    }
}
