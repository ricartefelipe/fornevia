<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Branch;
use App\Models\Identity\Membership;
use App\Services\Platform\PlanLimits;
use App\Support\Auth\SalesPortfolio;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Painel')]
final class Dashboard extends Component
{
    public function render(PlanLimits $limits): View
    {
        return view('livewire.staff.dashboard', [
            'subscription' => $limits->current(),
            'staffCount' => Membership::query()
                ->where('kind', MembershipKind::Staff->value)
                ->where('status', MembershipStatus::Active->value)
                ->count(),
            'buyerCount' => app(SalesPortfolio::class)->companies(BuyerCompany::query())->count(),
            'branchCount' => Branch::query()->count(),
        ]);
    }
}
