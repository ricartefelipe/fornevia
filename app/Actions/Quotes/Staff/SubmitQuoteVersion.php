<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Staff;

use App\Data\Quotes\PricedQuoteLine;
use App\Data\Quotes\QuoteVersionData;
use App\Data\Quotes\ReviewedQuoteState;
use App\Domain\Ordering\Exceptions\PricesChanged;
use App\Domain\Pricing\DiscountViolation;
use App\Domain\Quotes\CommercialReview;
use App\Domain\Quotes\CommercialReviewLine;
use App\Domain\Quotes\Exceptions\CommercialApprovalNotAllowed;
use App\Domain\Quotes\Exceptions\CommercialApprovalRequired;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Domain\Shared\Percentage;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteCommercialReview;
use App\Models\Quotes\QuoteItem;
use App\Models\Quotes\QuoteVersion;
use App\Services\Audit\AuditRecorder;
use App\Services\Ordering\CheckoutReferences;
use App\Services\Ordering\PurchaseEligibility;
use App\Services\Platform\PlanLimits;
use App\Services\Pricing\PriceVersion;
use App\Services\Quotes\CommercialEvaluator;
use App\Services\Quotes\QuoteLocks;
use App\Services\Quotes\QuotePricing;
use App\Services\Quotes\QuoteSettings;
use App\Services\Quotes\QuoteVersionFingerprint;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Elaboração de uma versão da proposta pela equipe (RF-040, RN-041).
 *
 * O preço de referência vem do servidor e a equipe informa só o desconto
 * (RS-030). A avaliação comercial usa o limite de desconto do autor: com
 * violação, a justificativa é obrigatória e a versão vai para aprovação
 * comercial, salvo autoaprovação de quem tem permissão de aprovar e limite
 * suficiente. Sem violação (ou com autoaprovação), a versão é enviada na
 * mesma transação (SendQuoteVersion::sendLocked).
 *
 * Sob o lock da cotação, com o estado das versões exibido: uma nova versão
 * em preparo retira a anterior em preparo. Ordem de locks (ADR-006): chave
 * de idempotência → cotação → versões → (envio) saldos → reservas.
 */
final readonly class SubmitQuoteVersion
{
    public const int MAX_NOTES = 2000;

    public function __construct(
        private Access $access,
        private StaffQuoteQuery $quotes,
        private QuoteLocks $locks,
        private QuotePricing $pricing,
        private CommercialEvaluator $evaluator,
        private CheckoutReferences $references,
        private PurchaseEligibility $eligibility,
        private SendQuoteVersion $send,
        private IdempotencyGuard $idempotency,
        private PriceVersion $priceVersion,
        private PlanLimits $limits,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(QuoteVersionData $data): QuoteVersion
    {
        $this->access->authorize(Permission::QuotesManage);
        $quote = $this->quotes->find($data->quoteId);
        $membership = $this->access->membership();
        $authorLimit = Percentage::ofBasisPoints($this->access->maxDiscountBps());
        $canApprove = $this->access->can(Permission::PricingApprove);

        // Validações dentro do trabalho idempotente: a repetição de um envio
        // concluído devolve o resultado guardado, mesmo que preço, condição ou
        // prazo tenham mudado depois.
        $result = $this->idempotency->run('quote.version', $data->idempotencyKey, $data->fingerprintPayload(), function () use ($quote, $data, $membership, $authorLimit, $canApprove): array {
            $this->limits->assertWritable();
            $buyer = BuyerCompany::query()->findOrFail($quote->buyer_company_id);
            $issue = $this->eligibility->companyIssue($buyer);

            if ($issue !== null) {
                throw new InvalidQuote($issue);
            }

            $term = $this->references->allowedTerm($buyer, $data->paymentTermId)
                ?? throw new InvalidQuote('Condição de pagamento não disponível para esta empresa.', ['field' => 'payment_term']);
            $now = $this->clock->now();
            $terms = QuoteSettings::terms();
            $validUntil = $terms->validUntil($data->validUntilDay, $now);
            $holdUntil = $data->stockHoldUntilDay !== null && trim($data->stockHoldUntilDay) !== '' ? $terms->holdUntil($data->stockHoldUntilDay, $validUntil, $now) : null;
            $notes = self::text($data->notes, self::MAX_NOTES, 'notes', 'As observações devem ter até 2.000 caracteres.');
            $justification = self::text($data->justification, 1000, 'justification', 'A justificativa deve ter até 1.000 caracteres.');

            [$lines, $totals] = $this->pricing->price($buyer, $data->lines);

            if ($data->expectedTotalCents !== null && $data->expectedTotalCents !== $totals->total->cents) {
                throw new PricesChanged('Os valores mudaram desde a prévia. Confira o novo total antes de enviar.', [
                    'expected_total_cents' => $data->expectedTotalCents, 'current_total_cents' => $totals->total->cents,
                ]);
            }

            $review = $this->evaluator->evaluate($lines, $authorLimit);

            if ($review->requiresApproval() && mb_strlen((string) $justification) < 10) {
                throw new CommercialApprovalRequired(
                    'A proposta está fora da política comercial ('.implode('; ', array_map(static fn (DiscountViolation $v): string => mb_strtolower($v->label()), $review->violations())).'): justifique a exceção (ao menos 10 caracteres).',
                    ['field' => 'justification'],
                );
            }

            $selfApprove = $data->selfApprove && $review->requiresApproval();

            if ($selfApprove && ! $review->selfApprovable($canApprove)) {
                throw new CommercialApprovalNotAllowed('Você não pode aprovar esta exceção: exige permissão de aprovar preços e limite de desconto de ao menos '.$review->maxDiscount()->format().'.', ['field' => 'self_approve']);
            }

            $locked = $this->locks->lock($quote->id);
            $versions = $this->locks->versions($locked);

            if ($locked->status->isTerminal()) {
                throw new QuoteNotOpen('A cotação está encerrada.');
            }

            if (! ReviewedQuoteState::of($versions)->equals($data->reviewed)) {
                throw new QuoteVersionOutdated('Outra pessoa enviou, submeteu ou retirou uma versão desta cotação. Recarregue a página antes de continuar.');
            }

            foreach ($versions->filter(static fn (QuoteVersion $v): bool => $v->status->isPreparing()) as $preparing) {
                $preparing->forceFill(['status' => QuoteVersionStatus::Withdrawn, 'closed_at' => $now])->save();
            }

            $ready = ! $review->requiresApproval() || $selfApprove;
            $version = $this->insertVersion($locked, $membership, $term, $validUntil, $holdUntil, $notes, $lines, $totals, $ready);
            $this->insertReview($version, $membership, $review, $justification, $selfApprove);
            DB::statement('set constraints quote_items_reconcile immediate');

            if ($ready) {
                $this->send->sendLocked($locked, $versions->push($version), $version, $membership);
            } else {
                $locked->forceFill(['last_activity_at' => $now])->save();
                $this->outbox->record('quote.commercial_approval_requested', 'quote', $locked->id, ['quote_id' => $locked->id, 'version_id' => $version->id]);
            }

            $this->audit->record('quote.version_submitted', $locked, [
                'version_id' => $version->id,
                'total_cents' => $version->total_cents->cents,
                'requires_approval' => $review->requiresApproval(),
                'self_approved' => $selfApprove,
                'sent' => $ready,
                'stock_hold_until' => $holdUntil?->toIso8601String(),
                'buyer_company_id' => $locked->buyer_company_id,
            ], $justification);

            return ['resource_type' => 'quote_version', 'resource_id' => $version->id, 'response' => ['status' => $version->status->value]];
        });

        return QuoteVersion::query()->findOrFail($result->resourceId);
    }

    /**
     * @param  list<PricedQuoteLine>  $lines
     */
    private function insertVersion(Quote $quote, Membership $membership, \App\Models\Pricing\PaymentTerm $term, \Carbon\CarbonImmutable $validUntil, ?\Carbon\CarbonImmutable $holdUntil, ?string $notes, array $lines, \App\Domain\Ordering\DocumentTotals $totals, bool $ready): QuoteVersion
    {
        $snapshot = CheckoutReferences::termSnapshot($term);
        $fingerprint = QuoteVersionFingerprint::of(array_map(static fn (PricedQuoteLine $line): array => [
            'sku_id' => $line->sku->id,
            'quantity' => $line->amounts->quantity->toDatabase(),
            'unit_price_cents' => $line->amounts->unitPrice->cents,
            'discount_bps' => $line->amounts->discount->basisPoints,
            'line_total_cents' => $line->amounts->total->cents,
        ], $lines), $totals->subtotal->cents, $totals->total->cents, $snapshot, $validUntil, $holdUntil, $notes);

        $version = new QuoteVersion;
        $version->forceFill([
            'buyer_company_id' => $quote->buyer_company_id,
            'quote_id' => $quote->id,
            'status' => $ready ? QuoteVersionStatus::Ready : QuoteVersionStatus::PendingApproval,
            'created_by_user_id' => $membership->user_id,
            'created_by_membership_id' => $membership->id,
            'payment_term_id' => $term->id,
            'payment_term' => $snapshot,
            'valid_until' => $validUntil,
            'stock_hold_until' => $holdUntil,
            'item_count' => count($lines),
            'subtotal_cents' => $totals->subtotal,
            'discount_cents' => $totals->discount,
            'total_cents' => $totals->total,
            'price_version' => $this->priceVersion->current(),
            'notes' => $notes,
            'content_fingerprint' => $fingerprint,
            'stock_hold_status' => StockHoldStatus::None,
        ])->save();

        $pricedOn = $this->clock->today()->toDateString();

        foreach ($lines as $line) {
            $source = $line->price->snapshot();
            $source['priced_on'] = $pricedOn;

            (new QuoteItem)->forceFill([
                'buyer_company_id' => $quote->buyer_company_id,
                'quote_version_id' => $version->id,
                'line_number' => $line->lineNumber,
                'sku_id' => $line->sku->id,
                'sku_code' => $line->sku->code,
                'product_name' => $line->sku->product->name,
                'variant_label' => $line->sku->variant_label,
                'unit_code' => $line->sku->salesUnit->code,
                'package_label' => $line->sku->package_label,
                'conversion_factor' => (string) $line->sku->conversion_factor,
                'packaging_fingerprint' => $line->sku->packagingFingerprint(),
                'quantity' => $line->amounts->quantity,
                'base_quantity' => $line->baseQuantity,
                'unit_price_cents' => $line->amounts->unitPrice,
                'discount_bps' => $line->amounts->discount->basisPoints,
                'unit_net_price_cents' => $line->amounts->unitNetPrice,
                'line_gross_cents' => $line->amounts->gross,
                'line_total_cents' => $line->amounts->total,
                'price_source' => $source,
            ])->save();
        }

        return $version;
    }

    private function insertReview(QuoteVersion $version, Membership $membership, CommercialReview $review, ?string $justification, bool $selfApprove): void
    {
        $now = $this->clock->now();

        (new QuoteCommercialReview)->forceFill([
            'buyer_company_id' => $version->buyer_company_id,
            'quote_version_id' => $version->id,
            'evaluated_by_user_id' => $membership->user_id,
            'evaluated_by_membership_id' => $membership->id,
            'author_limit_bps' => $review->authorLimit->basisPoints,
            'max_discount_bps' => $review->maxDiscount()->basisPoints,
            'requires_approval' => $review->requiresApproval(),
            'violations' => array_map(static fn (DiscountViolation $v): string => $v->value, $review->violations()),
            'lines' => array_map(static fn (CommercialReviewLine $line): array => $line->toArray(), $review->lines),
            'justification' => $review->requiresApproval() ? $justification : null,
            'decision' => $selfApprove ? 'approved' : null,
            'decided_by_user_id' => $selfApprove ? $membership->user_id : null,
            'decided_by_membership_id' => $selfApprove ? $membership->id : null,
            'decider_limit_bps' => $selfApprove ? $review->authorLimit->basisPoints : null,
            'decided_at' => $selfApprove ? $now : null,
            'self_approved' => $selfApprove,
        ])->save();
    }

    private static function text(?string $value, int $max, string $field, string $message): ?string
    {
        $value = $value !== null && trim($value) !== '' ? trim($value) : null;

        if ($value !== null && mb_strlen($value) > $max) {
            throw new InvalidQuote($message, ['field' => $field]);
        }

        return $value;
    }
}
