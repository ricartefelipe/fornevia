<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Models\Buyers\BuyerCompany;
use App\Support\Auth\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Portal do comprador')]
final class PortalHome extends Component
{
    public function render(Access $access): View
    {
        $company = BuyerCompany::query()
            ->with('salesRep.user')
            ->findOrFail($access->buyerCompanyId());

        return view('livewire.portal.portal-home', [
            'company' => $company,
            'membership' => $access->membership(),
        ]);
    }
}
