<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Actions\Buyers\SaveBuyerCompany;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Identity\Permission;
use App\Livewire\Staff\Buyers\Forms\BuyerCompanyForm;
use App\Models\Buyers\BuyerCompany;
use App\Support\Auth\Access;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Empresas compradoras do distribuidor: busca por nome ou CNPJ, filtro de
 * situação e cadastro (visível apenas a quem tem buyers.manage; a Action
 * autoriza de qualquer forma).
 */
#[Layout('layouts.app')]
#[Title('Empresas compradoras')]
final class BuyersIndex extends Component
{
    use WithPagination;

    public const int PER_PAGE = 25;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'situacao', except: '')]
    public string $status = '';

    public bool $creating = false;

    public BuyerCompanyForm $form;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    public function openForm(): void
    {
        $this->form->reset();
        $this->resetErrorBag();
        $this->creating = true;
    }

    public function closeForm(): void
    {
        $this->form->reset();
        $this->resetErrorBag();
        $this->creating = false;
    }

    public function save(SaveBuyerCompany $save): void
    {
        $data = $this->form->toData();

        if ($data === null) {
            return;
        }

        try {
            $company = $save->handle(null, $data);
        } catch (BusinessRuleViolation $e) {
            $this->form->addViolation($e);

            return;
        }

        session()->flash('success', "Empresa {$company->trade_name} cadastrada.");
        $this->redirectRoute('buyers.show', ['buyer' => $company->id]);
    }

    public function render(): View
    {
        $canManage = app(Access::class)->can(Permission::BuyersManage);

        return view('livewire.staff.buyers.buyers-index', [
            'companies' => $this->companies(),
            'statuses' => BuyerCompanyStatus::cases(),
            'canManage' => $canManage,
            'groupOptions' => $canManage && $this->creating ? BuyerOptions::customerGroups() : [],
            'salesRepOptions' => $canManage && $this->creating ? BuyerOptions::salesReps() : [],
            'filtered' => trim($this->search) !== '' || $this->status !== '',
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, BuyerCompany>
     */
    private function companies(): LengthAwarePaginator
    {
        $query = BuyerCompany::query()
            ->with(['customerGroup', 'salesRep.user'])
            ->orderBy('trade_name')
            ->orderBy('id');

        $term = trim(mb_substr($this->search, 0, 100));

        if ($term !== '') {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $digits = (string) preg_replace('/\D/', '', $term);
            // Busca por CNPJ quando o termo tem apenas dígitos e pontuação do documento.
            $byDocument = $digits !== '' && preg_match('/^[\d.\/\s-]+$/u', $term) === 1;

            $query->where(static function (Builder $query) use ($like, $digits, $byDocument): void {
                $query->where('legal_name', 'ilike', $like)->orWhere('trade_name', 'ilike', $like);

                if ($byDocument) {
                    $query->orWhere('document', 'like', '%'.$digits.'%');
                }
            });
        }

        $status = BuyerCompanyStatus::tryFrom($this->status);

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return $query->paginate(self::PER_PAGE);
    }
}
