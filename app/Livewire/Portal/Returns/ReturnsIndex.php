<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Returns;

use App\Actions\Fulfillment\Portal\BuyerReturns;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Devoluções pedidas pela pessoa (ou pela empresa, com
 * portal.orders.view_all), da mais recente para a mais antiga.
 */
#[Layout('layouts.app')]
#[Title('Devoluções')]
final class ReturnsIndex extends Component
{
    use WithPagination;

    public function render(BuyerReturns $returns): View
    {
        return view('livewire.portal.returns.returns-index', [
            'returns' => $returns->query($returns->actor())->with('order')->orderByDesc('created_at')->orderByDesc('id')->paginate(20),
            'timezone' => (string) config('app.business_timezone'),
        ]);
    }
}
