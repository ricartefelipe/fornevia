<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Ordering;

use App\Actions\Approvals\RetryOrderConfirmation;
use App\Actions\Approvals\Support\OrderApprovalSummary;
use App\Actions\Fulfillment\Portal\RequestReturn;
use App\Actions\Ordering\CancelOrderItems;
use App\Actions\Ordering\Portal\BuyerOrders;
use App\Actions\Ordering\Portal\Reorder;
use App\Actions\Quotes\Portal\BuyerQuotes;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Fulfillment\ReturnReason;
use App\Enums\Fulfillment\ShipmentStatus;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Fulfillment\Shipment;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderEvent;
use App\Models\Ordering\OrderItem;
use App\Models\Quotes\QuoteVersion;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detalhe do pedido do comprador: itens, totais, estados separados
 * (comercial, aprovação, atendimento e financeiro), níveis e decisões da
 * aprovação interna, entrega, pagamento e histórico. Permite cancelar itens
 * ainda não expedidos (RF-062) a quem pode confirmar pedidos e comprar
 * novamente (recompra com preços vigentes).
 *
 * Pedido aguardando aprovação, ou aprovado e ainda não confirmado com o
 * distribuidor, só é cancelado por inteiro (libera a reserva de
 * orçamento). Pedido aprovado cuja confirmação foi recusada mostra o motivo
 * e permite nova tentativa (RetryOrderConfirmation), com a mesma
 * reavaliação de preços e condições da aprovação final (RN-054). A tela
 * lembra que a aprovação interna não garante estoque nem crédito (RN-056).
 *
 * @phpstan-import-type ApprovalLevel from OrderApprovalSummary
 */
#[Layout('layouts.app')]
#[Title('Pedido')]
final class OrderShow extends Component
{
    public const int MIN_REASON_LENGTH = 5;

    public const int MAX_REASON_LENGTH = 500;

    /** @var array<string, string> */
    private const array EVENT_LABELS = [
        'order.confirmed' => 'Pedido confirmado',
        'order.items_cancelled' => 'Itens cancelados',
        'order.cancelled' => 'Pedido cancelado',
        'order.shipment_created' => 'Separação iniciada',
        'order.shipment_cancelled' => 'Separação cancelada',
        'order.shipped' => 'Remessa expedida',
        'order.delivered' => 'Remessa entregue',
        'order.return_requested' => 'Devolução pedida',
        ...OrderApprovalSummary::EVENT_LABELS,
    ];

    /** @var array<string, string> quantidade a devolver por item (entrada não confiável) */
    public array $returnQuantities = [];

    public string $returnReasonCode = '';

    public string $returnReason = '';

    #[Locked]
    public string $returnKey = '';

    #[Locked]
    public string $orderId = '';

    /** @var array<string, string> quantidade a cancelar por item (entrada não confiável) */
    public array $cancelQuantities = [];

    public string $cancelReason = '';

    #[Locked]
    public ?string $message = null;

    #[Locked]
    public string $messageType = 'success';

    #[Locked]
    public ?string $failure = null;

    /** @var array{summary: string, added: int, rejected: int, lines: list<array<string, mixed>>}|null */
    #[Locked]
    public ?array $report = null;

    public function mount(string $order, BuyerOrders $orders): void
    {
        $this->orderId = $orders->find($orders->actor(), $order)->id;
        $this->returnKey = (string) Str::uuid7();
    }

    public function requestReturn(RequestReturn $request): mixed
    {
        $this->resetErrorBag();
        $this->failure = null;

        try {
            $return = $request->handle($this->orderId, $this->returnQuantities, $this->returnReasonCode, $this->returnReason, $this->returnKey);
        } catch (BusinessRuleViolation $e) {
            $field = $e->details()['field'] ?? null;
            match (true) {
                $field === 'reason_code' => $this->addError('returnReasonCode', $e->getMessage()),
                $field === 'reason' => $this->addError('returnReason', $e->getMessage()),
                is_string($field) && str_starts_with($field, 'quantities.') => $this->addError('returnQuantities.'.substr($field, 11), $e->getMessage()),
                default => $this->failure = $e->getMessage(),
            };

            return null;
        }

        return $this->redirectRoute('portal.returns.show', $return->id);
    }

    /**
     * Mantém apenas entradas com chave de item e texto de tamanho limitado.
     */
    public function updatedCancelQuantities(): void
    {
        $sanitized = [];

        foreach ($this->cancelQuantities as $itemId => $value) {
            if (count($sanitized) >= 500) {
                break;
            }

            if (is_string($itemId) && Str::isUuid($itemId) && is_string($value)) {
                $sanitized[strtolower($itemId)] = mb_substr($value, 0, 21);
            }
        }

        $this->cancelQuantities = $sanitized;
    }

    public function cancelItems(BuyerOrders $orders, CancelOrderItems $cancel): void
    {
        $this->clearFeedback();
        $order = $orders->find($orders->actor(), $this->orderId);
        $reason = $this->reason();
        $quantities = $this->requestedCancellations($order);

        if ($reason === null || $quantities === null) {
            return;
        }

        $this->cancel($cancel, $order, $quantities, $reason);
    }

    public function cancelAll(BuyerOrders $orders, CancelOrderItems $cancel): void
    {
        $this->clearFeedback();
        $order = $orders->find($orders->actor(), $this->orderId);
        $reason = $this->reason();

        if ($reason === null) {
            return;
        }

        $this->cancel($cancel, $order, null, $reason);
    }

    /**
     * Nova tentativa de confirmar com o distribuidor um pedido aprovado
     * internamente cuja confirmação foi recusada.
     */
    public function retryConfirmation(BuyerOrders $orders, RetryOrderConfirmation $retry): void
    {
        $this->clearFeedback();
        $order = $orders->find($orders->actor(), $this->orderId);

        try {
            $outcome = $retry->handle($order);
        } catch (BusinessRuleViolation $e) {
            $this->failure = $e->getMessage();

            return;
        }

        if ($outcome === ApprovalOutcome::AwaitingConfirmation) {
            $this->failure = 'O pedido continua sem confirmação com o distribuidor. Veja o motivo atualizado abaixo.';

            return;
        }

        $this->message = $outcome->message();
        // Reavaliação que invalidou as aprovações: o pedido voltou para aprovação.
        $this->messageType = $outcome === ApprovalOutcome::Invalidated ? 'warning' : 'success';
    }

    public function reorder(Reorder $reorder): void
    {
        $this->clearFeedback();

        try {
            $this->report = $reorder->handle($this->orderId)->toDisplay();
        } catch (InvalidCartItem $e) {
            $this->failure = $e->getMessage();
        }
    }

    public function render(BuyerOrders $orders, BuyerQuotes $quotes, Access $access): View
    {
        $actor = $orders->actor();
        $order = $orders->find($actor, $this->orderId);
        $quoteOrigin = $order->source_quote_version_id !== null
            ? QuoteVersion::query()->with('quote')->find($order->source_quote_version_id)
            : null;
        $canPlace = $access->can(Permission::PortalOrdersPlace);
        $unconfirmed = in_array($order->commercial_status, [CommercialStatus::PendingApproval, CommercialStatus::Approved], true);
        $names = $orders->userNames([
            $order->placed_by_user_id,
            ...$order->events->map(static fn (OrderEvent $event): ?string => $event->actor_user_id)->all(),
        ]);

        return view('livewire.portal.ordering.order-show', [
            'order' => $order,
            'placedBy' => $names[$order->placed_by_user_id] ?? null,
            'events' => $order->events->map(static fn (OrderEvent $event): array => [
                'id' => $event->id,
                'label' => self::EVENT_LABELS[$event->type] ?? 'Atualização do pedido',
                'at' => $event->created_at,
                'actor' => $event->actor_user_id !== null ? ($names[$event->actor_user_id] ?? null) : null,
                'details' => self::eventDetails($event),
            ])->all(),
            'unitName' => $order->buyer_unit_id !== null ? BuyerUnit::query()->whereKey($order->buyer_unit_id)->value('name') : null,
            'costCenterName' => $order->cost_center_id !== null ? CostCenter::query()->whereKey($order->cost_center_id)->value('name') : null,
            'canCancel' => $orders->canCancel($order),
            'canCancelUnconfirmed' => $canPlace && $unconfirmed,
            'canRetry' => $canPlace && OrderApprovalSummary::awaitsConfirmation($order),
            'canReorder' => $access->can(Permission::PortalCart),
            'approvalLevels' => OrderApprovalSummary::levels($order),
            'shipments' => Shipment::query()->with(['items', 'events'])->where('order_id', $order->id)->where('status', '<>', ShipmentStatus::Cancelled->value)->orderBy('created_at')->get(),
            'returns' => ReturnRequest::query()->where('order_id', $order->id)->orderByDesc('created_at')->get(),
            'canRequestReturn' => $canPlace && $order->items->contains(static fn (OrderItem $item): bool => $item->returnable()->isPositive()),
            'returnReasons' => collect(ReturnReason::cases())->mapWithKeys(static fn (ReturnReason $r): array => [$r->value => $r->label()])->all(),
            'quoteOrigin' => $quoteOrigin,
            // Link só para quem pode abrir a cotação; os demais veem o número.
            'canOpenQuote' => $quoteOrigin !== null && $quotes->canOpen($actor, $quoteOrigin->quote_id),
        ])->title("Pedido {$order->number}");
    }

    /**
     * @param  array<string, Quantity>|null  $quantities
     */
    private function cancel(CancelOrderItems $cancel, Order $order, ?array $quantities, string $reason): void
    {
        try {
            $updated = $cancel->handle($order, $quantities, $reason);
        } catch (BusinessRuleViolation $e) {
            $this->failure = $e->getMessage();

            return;
        } catch (InvalidArgumentException) {
            $this->failure = 'Não foi possível cancelar: confira as quantidades informadas.';

            return;
        }

        $this->reset('cancelQuantities', 'cancelReason');
        // Decidido pelo pedido devolvido sob lock: uma confirmação concorrente
        // pode ter terminado antes do cancelamento.
        $this->message = match (true) {
            $updated->confirmed_at === null => 'Pedido cancelado antes da confirmação com o distribuidor. A reserva de orçamento do centro de custo, se houver, foi liberada.',
            $quantities === null || $updated->commercial_status === CommercialStatus::Cancelled => 'Pedido cancelado. As reservas e o crédito correspondentes foram liberados.',
            default => 'Itens cancelados. As reservas e o crédito correspondentes foram liberados.',
        };
    }

    private function reason(): ?string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $this->cancelReason));

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            $this->addError('cancelReason', 'Informe o motivo do cancelamento (ao menos '.self::MIN_REASON_LENGTH.' caracteres).');

            return null;
        }

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            $this->addError('cancelReason', 'O motivo deve ter até '.self::MAX_REASON_LENGTH.' caracteres.');

            return null;
        }

        return $reason;
    }

    /**
     * Quantidades digitadas por item (pt-BR), conferidas contra o saldo não
     * expedido e as casas decimais da unidade; null quando há erro.
     *
     * @return array<string, Quantity>|null
     */
    private function requestedCancellations(Order $order): ?array
    {
        $items = $order->items->keyBy('id');
        $decimals = UnitOfMeasure::query()->whereIn('code', $order->items->pluck('unit_code')->unique()->all())->pluck('decimals', 'code')->all();
        $quantities = [];
        $valid = true;

        foreach ($this->cancelQuantities as $itemId => $input) {
            if (trim($input) === '') {
                continue;
            }

            /** @var OrderItem|null $item */
            $item = $items->get(UntrustedId::uuid($itemId, OrderItem::class));

            if ($item === null) {
                throw (new ModelNotFoundException)->setModel(OrderItem::class, [$itemId]);
            }

            $key = "cancelQuantities.{$item->id}";

            try {
                $quantity = Quantity::parseLocalized($input);
            } catch (InvalidArgumentException|BusinessRuleViolation) {
                $this->addError($key, 'Informe a quantidade em números, por exemplo 2 ou 1,5.');
                $valid = false;

                continue;
            }

            $open = BuyerOrders::cancellable($item);
            $unitDecimals = isset($decimals[$item->unit_code]) ? (int) $decimals[$item->unit_code] : Quantity::SCALE;

            $error = match (true) {
                ! $quantity->isPositive() => 'Informe uma quantidade maior que zero.',
                $quantity->decimalPlaces() > $unitDecimals => $unitDecimals === 0
                    ? "A unidade {$item->unit_code} não aceita casas decimais."
                    : "A unidade {$item->unit_code} aceita no máximo {$unitDecimals} casas decimais.",
                $quantity->greaterThan($open) => "Só é possível cancelar até {$open->format()} {$item->unit_code} deste item (saldo não expedido).",
                default => null,
            };

            if ($error !== null) {
                $this->addError($key, $error);
                $valid = false;

                continue;
            }

            $quantities[$item->id] = $quantity;
        }

        if ($valid && $quantities === []) {
            $this->addError('cancelQuantities', 'Informe a quantidade a cancelar em ao menos um item.');

            return null;
        }

        return $valid ? $quantities : null;
    }

    /**
     * @return list<string>
     */
    private static function eventDetails(OrderEvent $event): array
    {
        $data = $event->data;
        $details = [];

        foreach (['total_cents' => 'Total', 'value_cents' => 'Valor cancelado'] as $key => $label) {
            if (isset($data[$key]) && is_int($data[$key])) {
                $details[] = $label.': '.Money::ofCents($data[$key])->format();
            }
        }

        if (isset($data['reason']) && is_string($data['reason']) && $data['reason'] !== '') {
            $details[] = 'Motivo: '.$data['reason'];
        }

        if (isset(OrderApprovalSummary::EVENT_LABELS[$event->type])) {
            array_push($details, ...OrderApprovalSummary::eventDetails($data));
        }

        return $details;
    }

    private function clearFeedback(): void
    {
        $this->resetErrorBag();
        $this->message = null;
        $this->messageType = 'success';
        $this->failure = null;
        $this->report = null;
    }
}
