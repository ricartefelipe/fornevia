<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Actions\Buyers\SaveBuyerUnit;
use App\Data\Buyers\BuyerUnitData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Livewire\Staff\Buyers\Concerns\InteractsWithBuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Unidades (obras, lojas, plantas) da empresa compradora.
 */
final class BuyerUnits extends Component
{
    use InteractsWithBuyerCompany;

    private const array FIELDS = ['code' => 'code', 'name' => 'name'];

    #[Locked]
    public ?string $editingId = null;

    public bool $formOpen = false;

    public string $code = '';

    public string $name = '';

    public bool $isActive = true;

    public ?string $message = null;

    public function mount(string $buyerId): void
    {
        $this->bindBuyer($buyerId);
    }

    public function create(): void
    {
        $this->cancel();
        $this->formOpen = true;
    }

    public function edit(string $unitId): void
    {
        $unit = $this->unit($unitId);

        $this->resetErrorBag();
        $this->editingId = $unit->id;
        $this->code = $unit->code;
        $this->name = $unit->name;
        $this->isActive = $unit->is_active;
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'formOpen', 'code', 'name', 'isActive');
        $this->resetErrorBag();
    }

    public function save(SaveBuyerUnit $save): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'isActive' => ['boolean'],
        ], [], ['code' => 'código', 'name' => 'nome']);

        $wasEditing = $this->editingId !== null;

        try {
            $save->handle(
                $this->company(),
                $wasEditing ? $this->unit((string) $this->editingId) : null,
                new BuyerUnitData(code: $this->code, name: $this->name, isActive: $this->isActive),
            );
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, self::FIELDS, 'code');

            return;
        }

        $this->cancel();
        $this->message = $wasEditing ? 'Unidade atualizada.' : 'Unidade cadastrada.';
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function render(): View
    {
        return view('livewire.staff.buyers.buyer-units', [
            'units' => BuyerUnit::query()
                ->where('buyer_company_id', $this->buyerId)
                ->orderByDesc('is_active')
                ->orderBy('code')
                ->get(),
            'canManage' => $this->canManage(),
        ]);
    }

    private function unit(string $unitId): BuyerUnit
    {
        return BuyerUnit::query()
            ->where('buyer_company_id', $this->buyerId)
            ->findOrFail(UntrustedId::uuid($unitId, BuyerUnit::class));
    }
}
