<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Ordering;

use App\Actions\Approvals\RetryOrderConfirmation;
use App\Actions\Ordering\CancelOrderItems;
use App\Actions\Ordering\Portal\BuyerOrders;
use App\Actions\Ordering\Portal\Reorder;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Approvals\OrderApprovalDecision;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderEvent;
use App\Models\Ordering\OrderItem;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Carbon\CarbonImmutable;
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
 * @phpstan-type ApprovalLevel array{
 *     id: string,
 *     number: int,
 *     required: int,
 *     status: ApprovalStepStatus,
 *     current: bool,
 *     decidedAt: CarbonImmutable|null,
 *     approvers: list<string>,
 *     approvals: int,
 *     decisions: list<array{id: string, actor: string, principal: string|null, decision: ApprovalDecision, comment: string|null, at: CarbonImmutable, invalidatedAt: CarbonImmutable|null}>
 * }
 */
#[Layout('layouts.app')]
#[Title('Pedido')]
final class OrderShow extends Component
{
    public const int MIN_REASON_LENGTH = 5;

    public const int MAX_REASON_LENGTH = 500;

    /**
     * Eventos da aprovação interna (também usados na tela da equipe).
     *
     * @var array<string, string>
     */
    public const array APPROVAL_EVENT_LABELS = [
        'order.submitted_for_approval' => 'Pedido enviado para aprovação interna',
        'order.approval_recorded' => 'Aprovação registrada',
        'order.approval_level_approved' => 'Nível de aprovação concluído',
        'order.approved' => 'Pedido aprovado internamente',
        'order.approval_rejected' => 'Pedido rejeitado na aprovação interna',
        'order.approvals_invalidated' => 'Aprovações invalidadas pela reavaliação de preços',
        'order.approval_step_reassigned' => 'Aprovadores do nível reatribuídos',
        'order.confirmation_pending' => 'Confirmação com o distribuidor pendente',
    ];

    /** @var array<string, string> */
    private const array EVENT_LABELS = [
        'order.confirmed' => 'Pedido confirmado',
        'order.items_cancelled' => 'Itens cancelados',
        'order.cancelled' => 'Pedido cancelado',
        ...self::APPROVAL_EVENT_LABELS,
    ];

    #[Locked]
    public string $orderId = '';

    /** @var array<string, string> quantidade a cancelar por item (entrada não confiável) */
    public array $cancelQuantities = [];

    public string $cancelReason = '';

    #[Locked]
    public ?string $message = null;

    #[Locked]
    public ?string $failure = null;

    /** @var array{summary: string, added: int, rejected: int, lines: list<array<string, mixed>>}|null */
    #[Locked]
    public ?array $report = null;

    public function mount(string $order, BuyerOrders $orders): void
    {
        $this->orderId = $orders->find($orders->actor(), $order)->id;
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

    public function render(BuyerOrders $orders, Access $access): View
    {
        $order = $orders->find($orders->actor(), $this->orderId);
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
            'canRetry' => $canPlace && self::awaitsConfirmation($order),
            'canReorder' => $access->can(Permission::PortalCart),
            'approvalLevels' => self::approvalLevels($order),
        ])->title("Pedido {$order->number}");
    }

    /**
     * Aprovado internamente e ainda não confirmado com o distribuidor.
     */
    public static function awaitsConfirmation(Order $order): bool
    {
        return $order->commercial_status === CommercialStatus::Approved && $order->approval_status === ApprovalStatus::Approved;
    }

    /**
     * Níveis de aprovação retratados na submissão, com os aprovadores, o
     * quórum atingido (titulares distintos, sem o solicitante) e as
     * decisões, inclusive as invalidadas pela reavaliação. Lido no contexto
     * de quem consulta: comprador da empresa ou equipe do distribuidor.
     *
     * @return list<ApprovalLevel>
     */
    public static function approvalLevels(Order $order): array
    {
        $steps = OrderApprovalStep::query()->where('order_id', $order->id)->orderBy('level_number')->get();

        if ($steps->isEmpty()) {
            return [];
        }

        $decisions = OrderApprovalDecision::query()
            ->where('order_id', $order->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('order_approval_step_id');

        $membershipIds = [];

        foreach ($steps as $step) {
            array_push($membershipIds, ...array_values($step->approver_membership_ids));
        }

        foreach ($decisions->flatten() as $decision) {
            /** @var OrderApprovalDecision $decision */
            $membershipIds[] = $decision->actor_membership_id;
            $membershipIds[] = $decision->principal_membership_id;
        }

        $names = Membership::query()
            ->with('user')
            ->whereKey(array_values(array_unique($membershipIds)))
            ->get()
            ->mapWithKeys(static fn (Membership $m): array => [$m->id => $m->user->name])
            ->all();
        $name = static fn (string $id): string => $names[$id] ?? 'Usuário não identificado';
        $requester = (string) $order->placed_by_membership_id;
        $currentId = $order->approval_status === ApprovalStatus::Pending
            ? $steps->first(static fn (OrderApprovalStep $s): bool => $s->status === ApprovalStepStatus::Pending)?->id
            : null;
        $levels = [];

        foreach ($steps as $step) {
            /** @var \Illuminate\Support\Collection<int, OrderApprovalDecision> $stepDecisions */
            $stepDecisions = $decisions->get($step->id, collect());
            $approvedBy = [];

            foreach ($stepDecisions as $decision) {
                if ($decision->invalidated_at === null && $decision->decision === ApprovalDecision::Approved
                    && $decision->principal_membership_id !== $requester && $decision->actor_membership_id !== $requester) {
                    $approvedBy[$decision->principal_membership_id] = true;
                }
            }

            $levels[] = [
                'id' => $step->id,
                'number' => $step->level_number,
                'required' => $step->required_approvals,
                'status' => $step->status,
                'current' => $step->id === $currentId,
                'decidedAt' => $step->decided_at,
                'approvers' => array_values(array_map($name, $step->approver_membership_ids)),
                'approvals' => count($approvedBy),
                'decisions' => array_values($stepDecisions->map(static fn (OrderApprovalDecision $d): array => [
                    'id' => $d->id,
                    'actor' => $name($d->actor_membership_id),
                    'principal' => $d->principal_membership_id !== $d->actor_membership_id ? $name($d->principal_membership_id) : null,
                    'decision' => $d->decision,
                    'comment' => $d->comment,
                    'at' => $d->created_at,
                    'invalidatedAt' => $d->invalidated_at,
                ])->all()),
            ];
        }

        return $levels;
    }

    /**
     * Detalhes legíveis dos eventos da aprovação interna (regra, nível e
     * totais).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function approvalEventDetails(array $data, bool $withTotal = false): array
    {
        $details = [];

        if ($withTotal && isset($data['total_cents']) && is_int($data['total_cents'])) {
            $details[] = 'Total: '.Money::ofCents($data['total_cents'])->format();
        }

        if (isset($data['rule']) && is_string($data['rule'])) {
            $details[] = 'Regra: '.$data['rule'];
        }

        if (isset($data['level']) && is_int($data['level'])) {
            $details[] = 'Nível '.$data['level'];
        }

        if (isset($data['previous_total_cents']) && is_int($data['previous_total_cents'])) {
            $details[] = 'Total anterior: '.Money::ofCents($data['previous_total_cents'])->format();
        }

        return $details;
    }

    /**
     * @param  array<string, Quantity>|null  $quantities
     */
    private function cancel(CancelOrderItems $cancel, Order $order, ?array $quantities, string $reason): void
    {
        $unconfirmed = in_array($order->commercial_status, [CommercialStatus::PendingApproval, CommercialStatus::Approved], true);

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
        $this->message = match (true) {
            $unconfirmed => 'Pedido cancelado antes da confirmação com o distribuidor. A reserva de orçamento do centro de custo, se houver, foi liberada.',
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

        if (isset(self::APPROVAL_EVENT_LABELS[$event->type])) {
            array_push($details, ...self::approvalEventDetails($data));
        }

        return $details;
    }

    private function clearFeedback(): void
    {
        $this->resetErrorBag();
        $this->message = null;
        $this->failure = null;
        $this->report = null;
    }
}
