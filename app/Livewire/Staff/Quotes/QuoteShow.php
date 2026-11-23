<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Quotes;

use App\Actions\Quotes\AddQuoteComment;
use App\Actions\Quotes\Staff\CloseQuote;
use App\Actions\Quotes\Staff\DecideCommercialApproval;
use App\Actions\Quotes\Staff\SendQuoteVersion;
use App\Actions\Quotes\Staff\StaffQuoteQuery;
use App\Actions\Quotes\Staff\WithdrawQuoteVersion;
use App\Data\Quotes\ReviewedQuoteState;
use App\Data\Quotes\ReviewedQuoteVersion;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Models\Identity\User;
use App\Models\Quotes\QuoteComment;
use App\Models\Quotes\QuoteRequest;
use App\Models\Quotes\QuoteVersion;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detalhe da cotação para a equipe: solicitação e pedidos de revisão,
 * versões (inclusive em preparo) com a avaliação comercial, conversa e
 * notas internas. Custo e margem só com catalog.view_costs (RS-020), nunca
 * em propriedades públicas. Ações: aprovar ou reprovar (pricing.approve),
 * enviar, retirar, encerrar e comentar (quotes.manage), sempre sobre o
 * estado exibido na última renderização (#[Locked]).
 */
#[Layout('layouts.app')]
#[Title('Cotação')]
final class QuoteShow extends Component
{
    #[Locked]
    public string $quoteId = '';

    #[Locked]
    public ?string $withBuyerVersionId = null;

    #[Locked]
    public ?string $preparingVersionId = null;

    #[Locked]
    public string $preparingFingerprint = '';

    public string $decisionComment = '';

    public bool $sendNow = true;

    public string $reason = '';

    public string $comment = '';

    public bool $internal = false;

    #[Locked]
    public ?string $message = null;

    #[Locked]
    public ?string $failure = null;

    public function mount(string $quote, StaffQuoteQuery $quotes): void
    {
        $this->quoteId = $quotes->find($quote)->id;
    }

    public function decide(bool $approve, DecideCommercialApproval $decide, Access $access): void
    {
        $this->clear();
        // "Aprovar e enviar" só para quem também elabora propostas.
        $sendNow = $approve && $this->sendNow && $access->can(Permission::QuotesManage);

        try {
            $decide->handle($this->quoteId, new ReviewedQuoteVersion((string) $this->preparingVersionId, $this->preparingFingerprint), $approve, $this->decisionComment, $sendNow);
        } catch (BusinessRuleViolation $e) {
            ($e->details()['field'] ?? null) === 'comment' ? $this->addError('decisionComment', $e->getMessage()) : $this->failure = $e->getMessage();

            return;
        }

        $this->reset('decisionComment');
        $this->message = $approve ? ($sendNow ? 'Exceção aprovada e proposta enviada ao comprador.' : 'Exceção aprovada. A versão está pronta para envio.') : 'Versão reprovada na aprovação comercial.';
    }

    public function send(SendQuoteVersion $send): void
    {
        $this->clear();

        try {
            $send->handle($this->quoteId, $this->state(), (string) $this->preparingVersionId);
        } catch (BusinessRuleViolation $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->message = 'Proposta enviada ao comprador.';
    }

    public function withdraw(string $which, WithdrawQuoteVersion $withdraw): void
    {
        $this->clear();
        $versionId = $which === 'sent' ? $this->withBuyerVersionId : $this->preparingVersionId;

        try {
            $withdraw->handle($this->quoteId, $this->state(), (string) $versionId, $this->reason);
        } catch (BusinessRuleViolation $e) {
            ($e->details()['field'] ?? null) === 'reason' ? $this->addError('reason', $e->getMessage()) : $this->failure = $e->getMessage();

            return;
        }

        $this->reset('reason');
        $this->message = 'Versão retirada.';
    }

    public function close(CloseQuote $close): void
    {
        $this->clear();

        try {
            $close->handle($this->quoteId, $this->reason);
        } catch (BusinessRuleViolation $e) {
            ($e->details()['field'] ?? null) === 'reason' ? $this->addError('reason', $e->getMessage()) : $this->failure = $e->getMessage();

            return;
        }

        $this->reset('reason');
        $this->message = 'Cotação encerrada.';
    }

    public function addComment(AddQuoteComment $add): void
    {
        $this->clear();

        try {
            $add->forStaff($this->quoteId, $this->comment, $this->internal);
        } catch (BusinessRuleViolation $e) {
            $this->addError('comment', $e->getMessage());

            return;
        }

        $this->reset('comment', 'internal');
        $this->message = 'Comentário registrado.';
    }

    public function render(StaffQuoteQuery $quotes, Access $access, Clock $clock): View
    {
        $quote = $quotes->find($this->quoteId)->load('buyerCompany');
        $versions = QuoteVersion::query()->with(['items', 'review'])->where('quote_id', $quote->id)->orderByDesc('created_at')->orderByDesc('id')->get();
        $state = ReviewedQuoteState::of($versions);
        $preparing = $versions->firstWhere('id', $state->preparingVersionId);

        // O que esta renderização mostra é o que uma ação enviada dela altera.
        $this->withBuyerVersionId = $state->withBuyerVersionId;
        $this->preparingVersionId = $state->preparingVersionId;
        $this->preparingFingerprint = $preparing->content_fingerprint ?? '';

        $userIds = $versions->pluck('created_by_user_id')->merge($versions->pluck('review.decided_by_user_id'))->filter()->unique()->values()->all();

        return view('livewire.staff.quotes.quote-show', [
            'quote' => $quote,
            'versions' => $versions,
            'preparing' => $preparing,
            'withBuyer' => $versions->firstWhere('id', $state->withBuyerVersionId),
            'requests' => QuoteRequest::query()->with('items')->where('quote_id', $quote->id)->orderBy('sequence')->get(),
            'comments' => QuoteComment::query()->where('quote_id', $quote->id)->orderBy('created_at')->orderBy('id')->get(),
            'names' => User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all(),
            'canManage' => $access->can(Permission::QuotesManage),
            'canApprove' => $access->can(Permission::PricingApprove),
            'canViewCosts' => $access->can(Permission::CatalogViewCosts),
            'limit' => $access->maxDiscountBps(),
            'membershipId' => $access->membership()->id,
            'now' => $clock->now(),
            'preparingStatus' => QuoteVersionStatus::PendingApproval,
        ])->title("Cotação {$quote->number}");
    }

    private function state(): ReviewedQuoteState
    {
        return new ReviewedQuoteState($this->withBuyerVersionId, $this->preparingVersionId);
    }

    private function clear(): void
    {
        $this->resetErrorBag();
        $this->message = null;
        $this->failure = null;
    }
}
