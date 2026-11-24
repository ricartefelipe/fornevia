<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Actions\Buyers\ChangeBuyerCompanyStatus;
use App\Actions\Buyers\SaveBuyerCompany;
use App\Domain\Buyers\Justification;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Buyers\BuyerCompanyStatus;
use App\Livewire\Staff\Buyers\Concerns\InteractsWithBuyerCompany;
use App\Livewire\Staff\Buyers\Forms\BuyerCompanyForm;
use App\Models\Audit\AuditLog;
use App\Models\Buyers\BuyerCompany;
use App\Support\Auth\SalesPortfolio;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Página da empresa compradora: dados cadastrais, situação, grupo e
 * representante. Contatos, endereços, unidades, centros de custo,
 * condições de pagamento, usuários e histórico são seções (componentes
 * filhos) que recebem apenas o identificador da empresa.
 */
#[Layout('layouts.app')]
#[Title('Empresa compradora')]
final class BuyerShow extends Component
{
    use InteractsWithBuyerCompany;

    public BuyerCompanyForm $form;

    public bool $editing = false;

    public string $newStatus = '';

    public string $statusReason = '';

    public ?string $message = null;

    public function mount(BuyerCompany $buyer): void
    {
        $this->buyerId = $buyer->id;
    }

    public function startEditing(): void
    {
        if (! $this->canManage()) {
            return;
        }

        $this->form->fillFrom($this->company());
        $this->resetErrorBag();
        $this->message = null;
        $this->editing = true;
    }

    public function cancelEditing(): void
    {
        $this->form->reset();
        $this->resetErrorBag();
        $this->editing = false;
    }

    public function saveProfile(SaveBuyerCompany $save): void
    {
        $data = $this->form->toData();

        if ($data === null) {
            return;
        }

        try {
            $save->handle($this->company(), $data);
        } catch (BusinessRuleViolation $e) {
            $this->form->addViolation($e);

            return;
        }

        $this->cancelEditing();
        $this->message = 'Dados cadastrais atualizados.';
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function changeStatus(ChangeBuyerCompanyStatus $change): void
    {
        $this->validate([
            'newStatus' => ['required', Rule::enum(BuyerCompanyStatus::class)],
            'statusReason' => ['required', 'string', 'min:'.Justification::MIN_LENGTH, 'max:'.Justification::MAX_LENGTH],
        ], [], ['newStatus' => 'nova situação', 'statusReason' => 'justificativa']);

        try {
            $company = $change->handle($this->company(), BuyerCompanyStatus::from($this->newStatus), $this->statusReason);
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['reason' => 'statusReason', 'status' => 'newStatus'], 'statusReason');

            return;
        }

        $this->reset('newStatus', 'statusReason');
        $this->resetErrorBag();
        $this->message = "Situação alterada para \"{$company->status->label()}\".";
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function render(): View
    {
        $company = app(SalesPortfolio::class)->companies(BuyerCompany::query())->with(['customerGroup', 'salesRep.user', 'salesRep.role'])->findOrFail($this->buyerId);
        $canManage = $this->canManage();

        $lastStatusChange = AuditLog::query()
            ->with('actor')
            ->where('subject_type', $company->getMorphClass())
            ->where('subject_id', $company->id)
            ->where('action', 'buyer_company.status_changed')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return view('livewire.staff.buyers.buyer-show', [
            'company' => $company,
            'canManage' => $canManage,
            'lastStatusChange' => $lastStatusChange,
            'statusOptions' => array_diff_key(BuyerOptions::statuses(), [$company->status->value => true]),
            'groupOptions' => $canManage && $this->editing ? BuyerOptions::customerGroups() : [],
            'salesRepOptions' => $canManage && $this->editing ? BuyerOptions::salesReps($company->sales_rep_membership_id) : [],
        ]);
    }
}
