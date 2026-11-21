<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Approvals\Inbox;

use App\Actions\Approvals\Queries\ApprovalInbox;
use App\Actions\Approvals\Queries\InboxTab;
use App\Support\Clock\Clock;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Fila de aprovação interna do comprador (RF-050): pedidos que aguardam a
 * decisão do usuário, os que ele já decidiu e, com portal.approval_rules,
 * os bloqueados por falta de aprovadores elegíveis (RN-052). Aba vinda da
 * URL é entrada não confiável: valor desconhecido ou sem permissão volta à
 * aba principal.
 */
#[Layout('layouts.app')]
#[Title('Aprovações')]
final class ApprovalsInbox extends Component
{
    use WithPagination;

    #[Url(as: 'aba', except: 'pendentes')]
    public string $tab = 'pendentes';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'livewire.portal.approvals.inbox.pagination';
    }

    public function render(ApprovalInbox $inbox, Clock $clock): View
    {
        $actor = $inbox->actor();
        $seesBlocked = $inbox->seesBlocked();
        $tab = InboxTab::tryFrom($this->tab);

        if ($tab === null || ($tab === InboxTab::Blocked && ! $seesBlocked)) {
            $tab = InboxTab::Awaiting;
        }

        $this->tab = $tab->value;
        $overview = $inbox->overview($actor, $tab, $this->currentPage());

        // Página além da última (a fila mudou ou a URL foi editada): volta à última existente.
        if ($overview->entries->isEmpty() && $overview->entries->total() > 0) {
            $this->setPage($overview->entries->lastPage());
            $overview = $inbox->overview($actor, $tab, $overview->entries->lastPage());
        }

        return view('livewire.portal.approvals.inbox.approvals-inbox', [
            'overview' => $overview,
            'tabs' => $seesBlocked ? InboxTab::cases() : [InboxTab::Awaiting, InboxTab::Decided],
            'membershipId' => $actor->membership->id,
            'now' => $clock->now(),
        ]);
    }

    private function currentPage(): int
    {
        $page = filter_var($this->getPage(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($page) ? $page : 1;
    }
}
