<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Staff;

use App\Data\Quotes\ReviewedQuoteState;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteExpired;
use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Enums\Quotes\StockHoldStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteItem;
use App\Models\Quotes\QuoteVersion;
use App\Services\Audit\AuditRecorder;
use App\Services\Ordering\CheckoutReferences;
use App\Services\Ordering\PurchaseEligibility;
use App\Services\Platform\PlanLimits;
use App\Services\Quotes\QuoteLocks;
use App\Services\Quotes\QuoteStockHolds;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Outbox\Outbox;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Envio de uma versão pronta ao comprador (RF-040). Também é o passo final
 * da submissão sem exceção à política e da aprovação comercial com envio.
 *
 * Sob o lock da cotação, com o estado exibido: validade e prazo da reserva
 * futuros; condição de pagamento ainda permitida e com a mesma definição;
 * itens ainda elegíveis e com a mesma embalagem; empresa com permissão de
 * compra. A versão com o comprador é substituída (reserva encerrada); a
 * nova recebe o número seguinte e, se pedida, a reserva com prazo, tudo ou
 * nada (RN-043). O banco exige avaliação comercial sem violação ou aprovada.
 */
final readonly class SendQuoteVersion
{
    public function __construct(
        private Access $access,
        private StaffQuoteQuery $quotes,
        private QuoteLocks $locks,
        private QuoteStockHolds $holds,
        private CheckoutReferences $references,
        private PurchaseEligibility $eligibility,
        private PlanLimits $limits,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $quoteId, ReviewedQuoteState $reviewed, mixed $versionId): QuoteVersion
    {
        $this->access->authorize(Permission::QuotesManage);
        $quote = $this->quotes->find($quoteId);
        $versionId = UntrustedId::uuid($versionId, QuoteVersion::class);
        $membership = $this->access->membership();

        return DB::transaction(function () use ($quote, $reviewed, $versionId, $membership): QuoteVersion {
            $this->limits->assertWritable();
            $locked = $this->locks->lock($quote->id);
            $versions = $this->locks->versions($locked);
            $version = $versions->firstWhere('id', $versionId);

            if ($version === null || $version->status !== QuoteVersionStatus::Ready || ! ReviewedQuoteState::of($versions)->equals($reviewed)) {
                throw new QuoteVersionOutdated('A versão mudou desde que você abriu esta página. Recarregue antes de enviar.');
            }

            $this->sendLocked($locked, $versions, $version, $membership);
            $this->audit->record('quote.version_sent', $locked, ['version_id' => $version->id, 'version_number' => $version->version_number, 'buyer_company_id' => $locked->buyer_company_id]);

            return $version;
        });
    }

    /**
     * Envio com a cotação e as versões já bloqueadas.
     *
     * @param  Collection<int, QuoteVersion>  $versions
     */
    public function sendLocked(Quote $quote, Collection $versions, QuoteVersion $version, Membership $by): void
    {
        if ($quote->status->isTerminal()) {
            throw new QuoteNotOpen('A cotação está encerrada.');
        }

        $now = $this->clock->now();

        if ($version->valid_until->lessThanOrEqualTo($now)) {
            throw new QuoteExpired('A validade desta versão já terminou. Elabore uma nova versão.');
        }

        if ($version->stock_hold_until !== null && $version->stock_hold_until->lessThanOrEqualTo($now)) {
            throw new InvalidQuote('O prazo da reserva desta versão já passou. Elabore uma nova versão.', ['field' => 'stock_hold_until']);
        }

        $buyer = BuyerCompany::query()->findOrFail($quote->buyer_company_id);
        $issue = $this->eligibility->companyIssue($buyer);

        if ($issue !== null) {
            throw new InvalidQuote($issue);
        }

        $term = $this->references->allowedTerm($buyer, $version->payment_term_id);

        if ($term === null || CheckoutReferences::termChanged($version->payment_term, $term)) {
            throw new InvalidQuote('A condição de pagamento da versão não está mais disponível com a mesma definição. Elabore uma nova versão.', ['field' => 'payment_term']);
        }

        $issue = $this->eligibility->itemsIssue($buyer, QuoteItem::query()->where('quote_version_id', $version->id)->orderBy('line_number')->get()
            ->map(static fn (QuoteItem $item): array => [
                'sku_id' => $item->sku_id, 'sku_code' => $item->sku_code, 'quantity' => $item->quantity, 'packaging' => $item->packaging_fingerprint,
            ])->all());

        if ($issue !== null) {
            throw new InvalidQuote($issue.' Elabore uma nova versão.', ['field' => 'lines']);
        }

        $current = $versions->first(static fn (QuoteVersion $v): bool => $v->status === QuoteVersionStatus::Sent);
        $this->holds->lockForSwap($version->stock_hold_until !== null ? $version : null, $current);

        if ($current !== null) {
            $this->holds->release($current, StockHoldEndReason::Superseded, locked: true);
            $current->forceFill(['status' => QuoteVersionStatus::Superseded, 'closed_at' => $now])->save();
        }

        if ($version->stock_hold_until !== null) {
            $this->holds->hold($version, $version->stock_hold_until);
        }

        $number = ((int) $versions->max('version_number')) + 1;
        $version->forceFill([
            'status' => QuoteVersionStatus::Sent,
            'version_number' => $number,
            'sent_at' => $now,
            'sent_by_membership_id' => $by->id,
            'stock_hold_status' => $version->stock_hold_until !== null ? StockHoldStatus::Active : StockHoldStatus::None,
        ])->save();

        $quote->forceFill(['status' => QuoteStatus::Proposed, 'last_activity_at' => $now])->save();

        $this->outbox->record('quote.proposal_sent', 'quote', $quote->id, [
            'quote_id' => $quote->id, 'number' => $quote->number, 'version_id' => $version->id, 'version_number' => $number,
            'total_cents' => $version->total_cents->cents, 'valid_until' => $version->valid_until->toIso8601String(),
            'stock_hold_until' => $version->stock_hold_until?->toIso8601String(),
        ]);
    }
}
