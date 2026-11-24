<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Returns;

use App\Enums\Fulfillment\ReturnStatus;
use App\Models\Fulfillment\ReturnRequest;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Devoluções: a analisar, autorizadas aguardando a chegada, em inspeção e
 * encerradas.
 */
#[Layout('layouts.app')]
#[Title('Devoluções')]
final class ReturnsIndex extends Component
{
    use WithPagination;

    public const array TABS = [
        'analisar' => ['A analisar', [ReturnStatus::Requested]],
        'aguardando' => ['Aguardando chegada', [ReturnStatus::Authorized]],
        'inspecao' => ['Em inspeção', [ReturnStatus::Received]],
        'encerradas' => ['Encerradas', [ReturnStatus::Closed, ReturnStatus::Rejected, ReturnStatus::Cancelled]],
    ];

    #[Url(as: 'fila', except: 'analisar')]
    public string $tab = 'analisar';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'analisar';

        return view('livewire.staff.returns.returns-index', [
            'tab' => $tab,
            'tabs' => array_map(static fn (array $t): string => $t[0], self::TABS),
            'returns' => ReturnRequest::query()
                ->with('order.buyerCompany')
                ->whereIn('status', array_map(static fn (ReturnStatus $s): string => $s->value, self::TABS[$tab][1]))
                ->orderBy($tab === 'encerradas' ? 'updated_at' : 'created_at', $tab === 'encerradas' ? 'desc' : 'asc')
                ->orderBy('id')
                ->paginate(25),
            'timezone' => (string) config('app.business_timezone'),
        ]);
    }
}
