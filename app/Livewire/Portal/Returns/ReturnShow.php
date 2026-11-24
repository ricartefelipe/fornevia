<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Returns;

use App\Actions\Fulfillment\Portal\BuyerReturns;
use App\Actions\Fulfillment\Portal\CancelReturn;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Fulfillment\ReturnStatus;
use App\Enums\Identity\Permission;
use App\Support\Auth\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Devolução no portal: itens, análise do distribuidor, recebimento,
 * resultado da inspeção e valor a devolver; desistência antes da análise.
 */
#[Layout('layouts.app')]
#[Title('Devolução')]
final class ReturnShow extends Component
{
    #[Locked]
    public string $returnId = '';

    public string $message = '';

    public string $failure = '';

    public function mount(string $return, BuyerReturns $returns): void
    {
        $this->returnId = $returns->find($returns->actor(), $return)->id;
    }

    public function cancel(CancelReturn $cancel): void
    {
        try {
            $cancel->handle($this->returnId);
        } catch (BusinessRuleViolation $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->message = 'Você desistiu da devolução.';
    }

    public function render(BuyerReturns $returns, Access $access): View
    {
        $return = $returns->find($returns->actor(), $this->returnId);
        $return->load(['items.orderItem', 'order']);

        return view('livewire.portal.returns.return-show', [
            'return' => $return,
            'canCancel' => $return->status === ReturnStatus::Requested && $access->can(Permission::PortalOrdersPlace),
            'timezone' => (string) config('app.business_timezone'),
        ])->title("Devolução {$return->number}");
    }
}
