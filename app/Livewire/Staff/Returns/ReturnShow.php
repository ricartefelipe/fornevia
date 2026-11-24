<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Returns;

use App\Actions\Fulfillment\Staff\DecideReturn;
use App\Actions\Fulfillment\Staff\ReceiveReturn;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Inventory\Warehouse;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Devolução: análise (autorizar com depósito e inspeção, ou recusar),
 * recebimento e inspeção por item.
 */
#[Layout('layouts.app')]
#[Title('Devolução')]
final class ReturnShow extends Component
{
    #[Locked]
    public string $returnId = '';

    public string $warehouseId = '';

    public bool $inspectionRequired = true;

    public string $comment = '';

    /** @var array<string, string> */
    public array $received = [];

    /** @var array<string, string> */
    public array $approved = [];

    /** @var array<string, string> */
    public array $notes = [];

    public string $message = '';

    public string $failure = '';

    public function mount(string $return): void
    {
        $this->returnId = ReturnRequest::query()->findOrFail(UntrustedId::uuid($return, ReturnRequest::class))->id;
    }

    public function approve(DecideReturn $decide): void
    {
        $this->attempt(fn () => $decide->authorize($this->returnId, $this->warehouseId, $this->inspectionRequired, $this->comment), 'Devolução autorizada. Aguarde a chegada dos itens.');
    }

    public function reject(DecideReturn $decide): void
    {
        $this->attempt(fn () => $decide->reject($this->returnId, $this->comment), 'Devolução recusada.');
    }

    public function receive(ReceiveReturn $receive): void
    {
        $this->attempt(fn () => $receive->receive($this->returnId, $this->received), 'Recebimento registrado.');
    }

    public function inspect(ReceiveReturn $receive): void
    {
        $this->attempt(fn () => $receive->inspect($this->returnId, $this->approved, $this->notes), 'Inspeção registrada. O aprovado voltou ao estoque disponível.');
    }

    public function render(Access $access): View
    {
        $return = ReturnRequest::query()->with(['items.orderItem', 'order.buyerCompany', 'warehouse'])->findOrFail($this->returnId);
        $warehouses = Warehouse::query()->where('is_active', true)->orderByDesc('priority')->orderBy('name')->get();
        $this->warehouseId = $this->warehouseId !== '' ? $this->warehouseId : (string) ($warehouses->first()->id ?? '');

        foreach ($return->items as $item) {
            $this->received[$item->id] ??= $item->quantity->format();
            $this->approved[$item->id] ??= $item->quantity_received->format();
        }

        return view('livewire.staff.returns.return-show', [
            'return' => $return,
            'warehouses' => $warehouses->mapWithKeys(static fn (Warehouse $w): array => [$w->id => $w->name])->all(),
            'canManage' => $access->can(Permission::ReturnsManage),
            'timezone' => (string) config('app.business_timezone'),
        ])->title("Devolução {$return->number}");
    }

    /**
     * @param  Closure(): mixed  $action
     */
    private function attempt(Closure $action, string $success): void
    {
        $this->resetErrorBag();
        $this->message = '';
        $this->failure = '';

        try {
            $action();
        } catch (BusinessRuleViolation $e) {
            $field = $e->details()['field'] ?? null;
            is_string($field) && preg_match('/^(received|approved)\./', $field) === 1 ? $this->addError($field, $e->getMessage())
                : ($field === 'comment' ? $this->addError('comment', $e->getMessage()) : $this->failure = $e->getMessage());

            return;
        }

        $this->message = $success;
    }
}
