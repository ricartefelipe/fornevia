<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Quotes;

use App\Actions\Ordering\Portal\CheckoutOptions;
use App\Actions\Quotes\AddQuoteComment;
use App\Actions\Quotes\Portal\AcceptQuoteVersion;
use App\Actions\Quotes\Portal\BuyerQuotes;
use App\Actions\Quotes\Portal\CancelQuoteRequest;
use App\Actions\Quotes\Portal\CounterQuoteProposal;
use App\Actions\Quotes\Portal\RejectQuote;
use App\Data\Quotes\AcceptQuoteData;
use App\Data\Quotes\ReviewedQuoteVersion;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteCloseReason;
use App\Enums\Quotes\QuoteCommentVisibility;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldStatus;
use App\Models\Ordering\Order;
use App\Models\Quotes\QuoteComment;
use App\Models\Quotes\QuoteRequest;
use App\Models\Quotes\QuoteVersion;
use App\Services\Inventory\StockAvailability;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detalhe da cotação no portal: proposta vigente (itens, preços de tabela,
 * descontos, totais, condição, validade), situação do estoque (reserva com
 * prazo ou disponibilidade consultada, RN-043), histórico de versões,
 * pedidos de revisão e conversa. Aceitar gera o pedido (exige
 * portal.orders.place); pedir revisão, recusar, cancelar e comentar.
 *
 * As ações valem para a versão exibida na última renderização (#[Locked]):
 * se a proposta mudou, a Action recusa e a tela mostra a atual.
 */
#[Layout('layouts.app')]
#[Title('Cotação')]
final class QuoteShow extends Component
{
    #[Locked]
    public string $quoteId = '';

    #[Locked]
    public string $reviewedVersionId = '';

    #[Locked]
    public string $reviewedFingerprint = '';

    #[Locked]
    public bool $reviewedHoldActive = false;

    #[Locked]
    public string $acceptKey = '';

    #[Locked]
    public string $counterKey = '';

    /** Versão cujas quantidades preenchem o pedido de revisão. */
    #[Locked]
    public string $counterVersionId = '';

    public string $shippingAddressId = '';

    public string $billingAddressId = '';

    public string $unitId = '';

    public string $costCenterId = '';

    public string $purchaseOrderReference = '';

    public string $orderNotes = '';

    public string $counterMessage = '';

    /** @var array<array-key, mixed> número da linha => nova quantidade (entrada não confiável) */
    public array $counterQuantities = [];

    public string $closeReasonCode = '';

    public string $closeReason = '';

    public string $comment = '';

    #[Locked]
    public ?string $message = null;

    #[Locked]
    public ?string $failure = null;

    public function mount(string $quote, BuyerQuotes $quotes): void
    {
        $this->quoteId = $quotes->find($quotes->actor(), $quote)->id;
        $this->acceptKey = (string) Str::uuid7();
        $this->counterKey = (string) Str::uuid7();
    }

    public function accept(AcceptQuoteVersion $accept): void
    {
        $this->clearFeedback();

        try {
            $order = $accept->handle($this->quoteId, new AcceptQuoteData(
                reviewed: $this->reviewed(withHold: true),
                idempotencyKey: $this->acceptKey,
                shippingAddressId: $this->shippingAddressId,
                billingAddressId: self::nullable($this->billingAddressId),
                buyerUnitId: self::nullable($this->unitId),
                costCenterId: self::nullable($this->costCenterId),
                purchaseOrderReference: self::nullable(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $this->purchaseOrderReference)), 0, 60)),
                notes: self::nullable(mb_substr(trim($this->orderNotes), 0, 2000)),
            ));
        } catch (BusinessRuleViolation $e) {
            $this->acceptKey = (string) Str::uuid7();
            $this->failure = $e->getMessage();

            return;
        }

        $this->redirectRoute('portal.orders.confirmation', ['order' => $order->id]);
    }

    public function counter(CounterQuoteProposal $counter): void
    {
        $this->clearFeedback();
        $quantities = [];

        foreach (array_slice($this->counterQuantities, 0, (int) config('quotes.max_lines'), true) as $line => $value) {
            if (is_numeric($line) && is_string($value) && trim($value) !== '') {
                $quantities[(int) $line] = mb_substr($value, 0, 21);
            }
        }

        try {
            $counter->handle($this->quoteId, $this->reviewed(), $this->counterMessage, $quantities, $this->counterKey);
        } catch (BusinessRuleViolation $e) {
            $this->counterKey = (string) Str::uuid7();
            ($e->details()['field'] ?? null) === 'message' ? $this->addError('counterMessage', $e->getMessage()) : $this->failure = $e->getMessage();

            return;
        }

        $this->counterKey = (string) Str::uuid7();
        $this->reset('counterMessage', 'counterQuantities');
        $this->message = 'Pedido de revisão enviado ao distribuidor. A proposta anterior não pode mais ser aceita.';
    }

    public function reject(RejectQuote $reject): void
    {
        $this->clearFeedback();

        try {
            $reject->handle($this->quoteId, $this->reviewed(), $this->closeReasonCode, $this->closeReason);
        } catch (BusinessRuleViolation $e) {
            $this->closeError($e);

            return;
        }

        $this->reset('closeReasonCode', 'closeReason');
        $this->message = 'Proposta recusada. A cotação foi encerrada.';
    }

    public function cancel(CancelQuoteRequest $cancel): void
    {
        $this->clearFeedback();

        try {
            $cancel->handle($this->quoteId, $this->closeReasonCode, $this->closeReason);
        } catch (BusinessRuleViolation $e) {
            $this->closeError($e);

            return;
        }

        $this->reset('closeReasonCode', 'closeReason');
        $this->message = 'Solicitação de cotação cancelada.';
    }

    public function addComment(AddQuoteComment $add): void
    {
        $this->clearFeedback();

        try {
            $add->forBuyer($this->quoteId, $this->comment);
        } catch (BusinessRuleViolation $e) {
            $this->addError('comment', $e->getMessage());

            return;
        }

        $this->reset('comment');
        $this->message = 'Comentário enviado.';
    }

    public function render(BuyerQuotes $quotes, Access $access, Clock $clock, StockAvailability $availability, CheckoutOptions $options): View
    {
        $actor = $quotes->actor();
        $quote = $quotes->find($actor, $this->quoteId);
        $now = $clock->now();
        // Só versões enviadas: a RLS esconde as demais do comprador.
        $versions = QuoteVersion::query()->with('items')->where('quote_id', $quote->id)->whereNotNull('version_number')->orderByDesc('version_number')->get();
        $current = $versions->first(static fn (QuoteVersion $v): bool => $v->status === QuoteVersionStatus::Sent);
        // Cotação vencida: a última versão enviada é a exibida, para pedir
        // revalidação.
        $shown = $current ?? ($quote->status === QuoteStatus::Expired ? $versions->first() : null);
        $holdActive = $current !== null && $current->stock_hold_status === StockHoldStatus::Active && $current->stock_hold_until?->greaterThan($now) === true;

        // O que esta renderização mostra é o que uma ação enviada dela altera.
        $this->reviewedVersionId = $shown->id ?? '';
        $this->reviewedFingerprint = $shown->content_fingerprint ?? '';
        $this->reviewedHoldActive = $current !== null && $current->stock_hold_status === StockHoldStatus::Active;

        // Quantidades do pedido de revisão sempre da versão exibida.
        if ($this->counterVersionId !== $this->reviewedVersionId) {
            $this->counterVersionId = $this->reviewedVersionId;
            $this->counterQuantities = [];

            foreach ($shown->items ?? [] as $item) {
                $this->counterQuantities[$item->line_number] = $item->quantity->format();
            }
        }

        $canAccept = $access->can(Permission::PortalOrdersPlace);
        $choices = null;

        if ($canAccept && $current !== null) {
            try {
                $choices = $options->handle(self::nullable($this->unitId));
                $this->shippingAddressId = $this->shippingAddressId !== '' ? $this->shippingAddressId : (string) $choices->defaultShippingAddressId;
            } catch (AuthorizationException) {
                $choices = null;
            }
        }

        return view('livewire.portal.quotes.quote-show', [
            'quote' => $quote,
            'current' => $shown,
            'expired' => $shown !== null && ($shown->status === QuoteVersionStatus::Expired || $shown->valid_until->lessThanOrEqualTo($now)),
            'holdActive' => $holdActive,
            'available' => $shown !== null && ! $holdActive ? $availability->bySku(array_values($shown->items->pluck('sku_id')->map(static fn (mixed $id): string => (string) $id)->all())) : [],
            'history' => $versions,
            'requests' => QuoteRequest::query()->with('items')->where('quote_id', $quote->id)->orderBy('sequence')->get(),
            'comments' => QuoteComment::query()->where('quote_id', $quote->id)->where('visibility', QuoteCommentVisibility::Shared->value)->orderBy('created_at')->orderBy('id')->get(),
            'order' => $versions->isEmpty() ? null : Order::query()->whereIn('source_quote_version_id', $versions->pluck('id')->all())->first(),
            'canAccept' => $canAccept,
            'choices' => $choices,
            'reasons' => QuoteCloseReason::forBuyer(),
            'awaiting' => $quote->status === QuoteStatus::Proposed,
            'cancellable' => in_array($quote->status, [QuoteStatus::Requested, QuoteStatus::Expired], true),
            'open' => ! $quote->status->isTerminal(),
            'now' => $now,
        ])->title("Cotação {$quote->number}");
    }

    /**
     * Versão exibida. A situação da reserva só conta para o aceite: recusar
     * ou pedir revisão vale para o conteúdo, mesmo que a reserva tenha
     * terminado depois da exibição.
     */
    private function reviewed(bool $withHold = false): ReviewedQuoteVersion
    {
        return new ReviewedQuoteVersion($this->reviewedVersionId, $this->reviewedFingerprint, $withHold ? $this->reviewedHoldActive : null);
    }

    private function closeError(BusinessRuleViolation $e): void
    {
        match ($e->details()['field'] ?? null) {
            'reason_code' => $this->addError('closeReasonCode', $e->getMessage()),
            'reason' => $this->addError('closeReason', $e->getMessage()),
            default => $this->failure = $e->getMessage(),
        };
    }

    private function clearFeedback(): void
    {
        $this->resetErrorBag();
        $this->message = null;
        $this->failure = null;
    }

    private static function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
