<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Credit;

use App\Actions\Credit\ListCreditAccounts;
use App\Data\Credit\CreditAccountFilters;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Money;
use App\Enums\Identity\Permission;
use App\Support\Auth\Access;
use App\Support\Auth\SalesPortfolio;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Crédito comercial por empresa compradora: limite, exceções vigentes,
 * comprometido, recebível, exposição, disponível e situação, com filtros
 * por situação (inclusive empresas sem conta), disponível abaixo de um
 * valor e busca por nome ou CNPJ.
 */
#[Layout('layouts.app')]
#[Title('Crédito comercial')]
final class CreditAccountsIndex extends Component
{
    use WithPagination;

    public const int PER_PAGE = 25;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'situacao', except: '')]
    public string $situation = '';

    /** Valor em reais digitado (entrada não confiável; ignorado se inválido). */
    #[Url(as: 'disponivel_abaixo', except: '')]
    public string $availableBelow = '';

    public function mount(): void
    {
        $this->validateAvailableBelow();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSituation(): void
    {
        $this->resetPage();
    }

    public function updatedAvailableBelow(): void
    {
        $this->resetPage();
        $this->validateAvailableBelow();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'situation', 'availableBelow');
        $this->resetErrorBag();
        $this->resetPage();
    }

    public function render(ListCreditAccounts $list, Access $access): View
    {
        $filters = new CreditAccountFilters(
            search: $this->search,
            situation: array_key_exists($this->situation, CreditAccountFilters::situations()) ? $this->situation : null,
            availableBelow: $this->parsedAvailableBelow(),
        );

        return view('livewire.staff.credit.credit-accounts-index', [
            'accounts' => $list->handle($filters, self::PER_PAGE),
            'portfolioOnly' => app(SalesPortfolio::class)->isRestricted(),
            'situations' => CreditAccountFilters::situations(),
            'canManage' => $access->can(Permission::CreditManage),
            'filtered' => $filters->hasAny() || trim($this->availableBelow) !== '',
        ]);
    }

    private function validateAvailableBelow(): void
    {
        $this->resetErrorBag('availableBelow');

        if (trim($this->availableBelow) === '') {
            return;
        }

        try {
            Money::parse($this->availableBelow);
        } catch (AmountOutOfRange) {
            $this->addError('availableBelow', 'Valor acima do limite suportado.');
        } catch (InvalidArgumentException|MathException) {
            $this->addError('availableBelow', 'Informe um valor em reais, por exemplo 10.000,00.');
        }
    }

    private function parsedAvailableBelow(): ?Money
    {
        $value = trim($this->availableBelow);

        if ($value === '') {
            return null;
        }

        try {
            return Money::parse($value);
        } catch (AmountOutOfRange|InvalidArgumentException|MathException) {
            return null;
        }
    }
}
