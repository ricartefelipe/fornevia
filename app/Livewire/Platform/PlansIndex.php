<?php

declare(strict_types=1);

namespace App\Livewire\Platform;

use App\Actions\Platform\SavePlan;
use App\Enums\Platform\PlatformModule;
use App\Models\Identity\User;
use App\Models\Platform\Plan;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Planos da plataforma (valores comerciais são hipóteses de produto).
 * Preços em reais na interface, persistidos em centavos.
 */
#[Layout('layouts.app')]
#[Title('Planos')]
final class PlansIndex extends Component
{
    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $monthlyPrice = '0,00';

    public string $setupFee = '0,00';

    public string $maxStaffUsers = '';

    public string $maxBranches = '';

    public string $maxOrdersPerMonth = '';

    public int $trialDays = 14;

    /** @var list<string> */
    public array $modules = [];

    public bool $isPublic = true;

    public bool $isActive = true;

    public ?string $message = null;

    public function edit(string $planId): void
    {
        $plan = Plan::query()->findOrFail($planId);
        $this->editingId = $plan->id;
        $this->code = $plan->code;
        $this->name = $plan->name;
        $this->monthlyPrice = self::fromCents($plan->monthly_price_cents);
        $this->setupFee = self::fromCents($plan->setup_fee_cents);
        $this->maxStaffUsers = (string) ($plan->max_staff_users ?? '');
        $this->maxBranches = (string) ($plan->max_branches ?? '');
        $this->maxOrdersPerMonth = (string) ($plan->max_orders_per_month ?? '');
        $this->trialDays = $plan->trial_days;
        $this->modules = $plan->modules;
        $this->isPublic = $plan->is_public;
        $this->isActive = $plan->is_active;
    }

    public function create(): void
    {
        $this->reset();
    }

    public function save(SavePlan $save): void
    {
        $this->validate([
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('plans', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:80'],
            'monthlyPrice' => ['required', 'regex:/^\d{1,9}(,\d{1,2})?$/'],
            'setupFee' => ['required', 'regex:/^\d{1,9}(,\d{1,2})?$/'],
            'maxStaffUsers' => ['nullable', 'integer', 'min:1'],
            'maxBranches' => ['nullable', 'integer', 'min:1'],
            'maxOrdersPerMonth' => ['nullable', 'integer', 'min:1'],
            'trialDays' => ['required', 'integer', 'min:0', 'max:90'],
            'modules' => ['array'],
            'modules.*' => [Rule::enum(PlatformModule::class)],
        ], [], ['monthlyPrice' => 'mensalidade', 'setupFee' => 'implantação']);

        /** @var User $actor */
        $actor = auth()->user();
        $save->handle($actor, $this->editingId !== null ? Plan::query()->findOrFail($this->editingId) : null, [
            'code' => $this->code,
            'name' => $this->name,
            'monthly_price_cents' => self::toCents($this->monthlyPrice),
            'setup_fee_cents' => self::toCents($this->setupFee),
            'max_staff_users' => $this->maxStaffUsers !== '' ? (int) $this->maxStaffUsers : null,
            'max_branches' => $this->maxBranches !== '' ? (int) $this->maxBranches : null,
            'max_orders_per_month' => $this->maxOrdersPerMonth !== '' ? (int) $this->maxOrdersPerMonth : null,
            'modules' => array_values(array_map(strval(...), $this->modules)),
            'trial_days' => $this->trialDays,
            'is_public' => $this->isPublic,
            'is_active' => $this->isActive,
        ]);

        $this->reset();
        $this->message = 'Plano salvo.';
    }

    public function render(): View
    {
        return view('livewire.platform.plans-index', [
            'plans' => Plan::query()->orderBy('sort_order')->orderBy('name')->get(),
            'availableModules' => PlatformModule::cases(),
        ]);
    }

    /**
     * Converte "1234,56" em centavos sem ponto flutuante.
     */
    public static function toCents(string $value): int
    {
        [$units, $fraction] = array_pad(explode(',', $value, 2), 2, '0');

        return (int) $units * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function fromCents(int $cents): string
    {
        return intdiv($cents, 100).','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
