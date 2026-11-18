<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Orders;

use App\Actions\Ordering\Staff\CancelStaffOrderItems;
use App\Actions\Ordering\Staff\LoadStaffOrderDetail;
use App\Actions\Ordering\Staff\StaffOrderDetail;
use App\Actions\Ordering\Staff\StaffOrderQuery;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Ordering\OrderItem;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detalhe do pedido para a equipe: cabeçalho com snapshots, itens, estados
 * separados, histórico, reservas por depósito e crédito comprometido;
 * custo e margem apenas com catalog.view_costs.
 *
 * Cancelamento total ou parcial de itens não expedidos (RF-062) por
 * CancelStaffOrderItems, que exige orders.manage. O pedido é identificado
 * por propriedade bloqueada e recarregado pelo escopo da equipe a cada
 * ação; identificadores de itens recebidos do cliente que não pertencem ao
 * pedido equivalem a registro inexistente.
 */
#[Layout('layouts.app')]
#[Title('Pedido')]
final class OrderShow extends Component
{
    public const int REASON_MIN = 5;

    public const int REASON_MAX = 500;

    #[Locked]
    public string $orderId = '';

    /**
     * Quantidades a cancelar por item, como digitadas (entrada não confiável).
     *
     * @var array<array-key, mixed>
     */
    public array $quantities = [];

    public string $cancelReason = '';

    public ?string $notice = null;

    /** @var list<string> */
    public array $releaseNotes = [];

    public function mount(string $order, StaffOrderQuery $orders): void
    {
        $this->orderId = $orders->find($order)->id;
    }

    /**
     * Cancela as quantidades informadas por item.
     */
    public function cancelSelected(CancelStaffOrderItems $cancel, StaffOrderQuery $orders): void
    {
        $this->clearFeedback();
        $this->validateReason();

        $order = $orders->find($this->orderId);
        $items = OrderItem::query()->where('order_id', $order->id)->get()->keyBy('id');
        $decimals = LoadStaffOrderDetail::unitDecimals($items);
        $requested = [];

        foreach ($this->quantities as $key => $value) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $item = $items->get(UntrustedId::uuid($key, OrderItem::class))
                ?? throw (new ModelNotFoundException)->setModel(OrderItem::class);
            $quantity = $this->requestedQuantity($item, $value, $decimals[$item->unit_code] ?? 0);

            if ($quantity !== null) {
                $requested[$item->id] = $quantity;
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        if ($requested === []) {
            $this->addError('quantities', 'Informe a quantidade a cancelar em ao menos um item.');

            return;
        }

        $this->run($cancel, $requested);
    }

    /**
     * Cancela todo o saldo não expedido do pedido.
     */
    public function cancelAll(CancelStaffOrderItems $cancel): void
    {
        $this->clearFeedback();
        $this->validateReason();
        $this->run($cancel, null);
    }

    public function render(LoadStaffOrderDetail $load, Access $access): View
    {
        $detail = $load->handle($this->orderId);

        return view('livewire.staff.orders.order-show', [
            'detail' => $detail,
            'order' => $detail->order,
            'canManage' => $access->can(Permission::OrdersManage),
            'canViewBuyer' => $access->can(Permission::BuyersView),
        ])->title('Pedido '.$detail->order->number);
    }

    /**
     * @param  array<string, Quantity>|null  $quantities
     */
    private function run(CancelStaffOrderItems $cancel, ?array $quantities): void
    {
        try {
            $outcome = $cancel->handle($this->orderId, $quantities, $this->cancelReason);
        } catch (BusinessRuleViolation $e) {
            $itemId = $e->details()['order_item_id'] ?? null;
            $this->addError(is_string($itemId) ? 'quantities.'.$itemId : 'cancellation', $e->getMessage());

            return;
        }

        $this->reset('quantities', 'cancelReason');
        $this->notice = $outcome->fullyCancelled
            ? "Pedido {$outcome->order->number} cancelado."
            : "Cancelamento registrado no pedido {$outcome->order->number}.";
        $this->releaseNotes = $outcome->summary();
    }

    private function validateReason(): void
    {
        $this->cancelReason = trim($this->cancelReason);
        $this->validate([
            'cancelReason' => ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX],
        ], [], ['cancelReason' => 'motivo do cancelamento']);
    }

    /**
     * Quantidade digitada (pt-BR) validada contra a unidade de venda, o saldo
     * cancelável e a conversão para a unidade base; erros vão para o campo.
     */
    private function requestedQuantity(OrderItem $item, mixed $value, int $decimals): ?Quantity
    {
        $field = 'quantities.'.$item->id;

        try {
            $quantity = Quantity::parseLocalized(is_string($value) || is_int($value) ? (string) $value : '');
        } catch (AmountOutOfRange) {
            $this->addError($field, 'Quantidade acima do limite suportado.');

            return null;
        } catch (InvalidArgumentException|MathException) {
            $this->addError($field, 'Informe uma quantidade válida, por exemplo 2 ou 1,5.');

            return null;
        }

        $open = StaffOrderDetail::openQuantity($item);
        $error = match (true) {
            ! $quantity->isPositive() => 'Informe uma quantidade maior que zero.',
            $quantity->decimalPlaces() > $decimals => $decimals === 0
                ? "A unidade {$item->unit_code} não aceita casas decimais."
                : "A unidade {$item->unit_code} aceita no máximo {$decimals} casas decimais.",
            $quantity->greaterThan($open) => "O saldo cancelável deste item é {$open->format()} {$item->unit_code}.",
            default => null,
        };

        if ($error === null) {
            try {
                $quantity->convertedBy($item->conversion_factor);
            } catch (InvalidArgumentException|AmountOutOfRange) {
                $error = 'Quantidade não representável na unidade base do produto.';
            }
        }

        if ($error !== null) {
            $this->addError($field, $error);

            return null;
        }

        return $quantity;
    }

    private function clearFeedback(): void
    {
        $this->resetErrorBag();
        $this->notice = null;
        $this->releaseNotes = [];
    }
}
