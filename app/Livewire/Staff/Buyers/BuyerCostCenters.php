<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Actions\Buyers\SaveCostCenter;
use App\Data\Buyers\CostCenterData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Livewire\Staff\Buyers\Concerns\InteractsWithBuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Centros de custo da empresa compradora (base de orçamentos e aprovações).
 */
final class BuyerCostCenters extends Component
{
    use InteractsWithBuyerCompany;

    private const array FIELDS = ['code' => 'code', 'name' => 'name', 'buyer_unit_id' => 'buyerUnitId'];

    #[Locked]
    public ?string $editingId = null;

    public bool $formOpen = false;

    public string $code = '';

    public string $name = '';

    public string $buyerUnitId = '';

    public bool $isActive = true;

    public ?string $message = null;

    public function mount(string $buyerId): void
    {
        $this->bindBuyer($buyerId);
    }

    /**
     * Unidades cadastradas em outra seção passam a aparecer na seleção.
     */
    #[On('buyer-updated')]
    public function refreshUnits(): void {}

    public function create(): void
    {
        $this->cancel();
        $this->formOpen = true;
    }

    public function edit(string $costCenterId): void
    {
        $costCenter = $this->costCenter($costCenterId);

        $this->resetErrorBag();
        $this->editingId = $costCenter->id;
        $this->code = $costCenter->code;
        $this->name = $costCenter->name;
        $this->buyerUnitId = (string) $costCenter->buyer_unit_id;
        $this->isActive = $costCenter->is_active;
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'formOpen', 'code', 'name', 'buyerUnitId', 'isActive');
        $this->resetErrorBag();
    }

    public function save(SaveCostCenter $save): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'buyerUnitId' => ['nullable', 'uuid'],
            'isActive' => ['boolean'],
        ], [], ['code' => 'código', 'name' => 'nome', 'buyerUnitId' => 'unidade']);

        $wasEditing = $this->editingId !== null;

        try {
            $save->handle(
                $this->company(),
                $wasEditing ? $this->costCenter((string) $this->editingId) : null,
                new CostCenterData(
                    code: $this->code,
                    name: $this->name,
                    buyerUnitId: $this->buyerUnitId !== '' ? $this->buyerUnitId : null,
                    isActive: $this->isActive,
                ),
            );
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, self::FIELDS, 'code');

            return;
        }

        $this->cancel();
        $this->message = $wasEditing ? 'Centro de custo atualizado.' : 'Centro de custo cadastrado.';
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function render(): View
    {
        return view('livewire.staff.buyers.buyer-cost-centers', [
            'costCenters' => CostCenter::query()
                ->with('unit')
                ->where('buyer_company_id', $this->buyerId)
                ->orderByDesc('is_active')
                ->orderBy('code')
                ->get(),
            'units' => $this->unitOptions(),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function unitOptions(): array
    {
        $selected = Str::isUuid($this->buyerUnitId) ? $this->buyerUnitId : null;

        return BuyerUnit::query()
            ->where('buyer_company_id', $this->buyerId)
            ->where(static function (Builder $query) use ($selected): void {
                $query->where('is_active', true);

                if ($selected !== null) {
                    $query->orWhere('id', $selected);
                }
            })
            ->orderBy('name')
            ->get()
            ->mapWithKeys(static fn (BuyerUnit $unit): array => [$unit->id => "{$unit->name} ({$unit->code})".($unit->is_active ? '' : ' · inativa')])
            ->all();
    }

    private function costCenter(string $costCenterId): CostCenter
    {
        return CostCenter::query()
            ->where('buyer_company_id', $this->buyerId)
            ->findOrFail(UntrustedId::uuid($costCenterId, CostCenter::class));
    }
}
