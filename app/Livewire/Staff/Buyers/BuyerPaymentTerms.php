<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Actions\Buyers\SetBuyerPaymentTerms;
use App\Domain\Shared\BusinessRuleViolation;
use App\Livewire\Staff\Buyers\Concerns\InteractsWithBuyerCompany;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * Condições de pagamento permitidas para a empresa compradora e a
 * condição padrão.
 */
final class BuyerPaymentTerms extends Component
{
    use InteractsWithBuyerCompany;

    /** @var array<array-key, mixed> identificadores escolhidos (entrada não confiável) */
    public array $selectedTermIds = [];

    public string $defaultTermId = '';

    public bool $editing = false;

    public ?string $message = null;

    public function mount(string $buyerId): void
    {
        $this->bindBuyer($buyerId);
    }

    public function startEditing(): void
    {
        $current = $this->assigned();

        $this->selectedTermIds = array_values($current->pluck('payment_term_id')->map(static fn (mixed $id): string => (string) $id)->all());
        $this->defaultTermId = (string) $current->firstWhere('is_default', true)?->payment_term_id;
        $this->resetErrorBag();
        $this->message = null;
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->reset('selectedTermIds', 'defaultTermId', 'editing');
        $this->resetErrorBag();
    }

    public function save(SetBuyerPaymentTerms $set): void
    {
        $this->validate([
            'selectedTermIds' => ['array', 'max:50'],
            'selectedTermIds.*' => ['string', 'uuid'],
            'defaultTermId' => ['nullable', 'uuid'],
        ], [], ['selectedTermIds' => 'condições permitidas', 'selectedTermIds.*' => 'condição', 'defaultTermId' => 'condição padrão']);

        try {
            $set->handle($this->company(), $this->selectedTermIds, $this->defaultTermId !== '' ? $this->defaultTermId : null);
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['default' => 'defaultTermId', 'terms' => 'selectedTermIds'], 'selectedTermIds');

            return;
        }

        $this->cancel();
        $this->message = 'Condições de pagamento atualizadas.';
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function render(): View
    {
        $assigned = $this->assigned();
        $assignedIds = $assigned->pluck('payment_term_id')->all();

        return view('livewire.staff.buyers.buyer-payment-terms', [
            'assigned' => $assigned,
            // Condições ativas e, na edição, as já atribuídas que tenham sido inativadas.
            'available' => $this->editing
                ? PaymentTerm::query()
                    ->where(static function (Builder $query) use ($assignedIds): void {
                        $query->where('is_active', true)->orWhereIn('id', $assignedIds);
                    })
                    ->orderBy('name')
                    ->get()
                : collect(),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * @return Collection<int, BuyerPaymentTerm>
     */
    private function assigned(): Collection
    {
        return BuyerPaymentTerm::query()
            ->with('paymentTerm')
            ->where('buyer_company_id', $this->buyerId)
            ->get()
            ->sortBy(static fn (BuyerPaymentTerm $row): string => ($row->is_default ? '0' : '1').mb_strtolower($row->paymentTerm->name))
            ->values();
    }
}
