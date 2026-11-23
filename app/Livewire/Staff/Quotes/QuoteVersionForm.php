<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Quotes;

use App\Actions\Quotes\Staff\StaffQuoteQuery;
use App\Actions\Quotes\Staff\SubmitQuoteVersion;
use App\Data\Quotes\PricedQuoteLine;
use App\Data\Quotes\QuoteLineInput;
use App\Data\Quotes\QuoteVersionData;
use App\Data\Quotes\ReviewedQuoteState;
use App\Domain\Pricing\DiscountViolation;
use App\Domain\Quotes\CommercialReview;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteRequest;
use App\Models\Quotes\QuoteVersion;
use App\Services\Quotes\CommercialEvaluator;
use App\Services\Quotes\QuotePricing;
use App\Services\Quotes\QuoteSettings;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Editor de uma nova versão da proposta: linhas pré-preenchidas com o
 * último pedido do comprador e os descontos da última versão; preço de
 * referência e avaliação comercial calculados no servidor a cada
 * renderização (prévia); condição de pagamento entre as permitidas,
 * validade, reserva opcional, observações e, fora da política,
 * justificativa e autoaprovação. O envio vale para a prévia e o estado das
 * versões exibidos (#[Locked]).
 */
#[Layout('layouts.app')]
#[Title('Nova versão da proposta')]
final class QuoteVersionForm extends Component
{
    #[Locked]
    public string $quoteId = '';

    #[Locked]
    public ?string $withBuyerVersionId = null;

    #[Locked]
    public ?string $preparingVersionId = null;

    #[Locked]
    public string $submitKey = '';

    #[Locked]
    public ?int $expectedTotalCents = null;

    /** @var array<array-key, mixed> linhas {sku, quantity, discount} (entrada não confiável) */
    public array $lines = [];

    public string $newSku = '';

    public string $paymentTermId = '';

    public string $validUntilDay = '';

    public string $stockHoldUntilDay = '';

    public string $notes = '';

    public string $justification = '';

    public bool $selfApprove = false;

    public function mount(string $quote, StaffQuoteQuery $quotes, Access $access, Clock $clock): void
    {
        $access->authorize(Permission::QuotesManage);
        $found = $quotes->find($quote);
        $this->quoteId = $found->id;
        $this->submitKey = (string) Str::uuid7();

        $request = QuoteRequest::query()->with('items')->where('quote_id', $found->id)->orderByDesc('sequence')->first();
        $last = QuoteVersion::query()->with('items')->where('quote_id', $found->id)->orderByDesc('created_at')->first();
        $discounts = $last?->items->mapWithKeys(static fn ($i): array => [$i->sku_id => Percentage::ofBasisPoints($i->discount_bps)->format()])->all() ?? [];

        foreach ($request->items ?? [] as $item) {
            $this->lines[] = ['sku' => $item->sku_code, 'quantity' => $item->quantity->format(), 'discount' => str_replace('%', '', $discounts[$item->sku_id] ?? '0')];
        }

        $this->paymentTermId = (string) ($last->payment_term_id ?? PaymentTerm::query()
            ->whereIn('id', BuyerPaymentTerm::query()->where('buyer_company_id', $found->buyer_company_id)->select('payment_term_id'))
            ->where('is_active', true)->orderBy('name')->orderBy('id')->value('id') ?? '');
        $this->validUntilDay = QuoteSettings::terms()->defaultValidityDay($clock->now(), (int) config('quotes.default_validity_days', 7));
    }

    public function addLine(): void
    {
        $code = mb_substr(trim($this->newSku), 0, 60);

        if ($code !== '' && count($this->lines) < (int) config('quotes.max_lines')) {
            $this->lines[] = ['sku' => $code, 'quantity' => '1', 'discount' => '0'];
        }

        $this->newSku = '';
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function submit(SubmitQuoteVersion $submit): void
    {
        $this->resetErrorBag();

        if (! Str::isUuid($this->paymentTermId)) {
            $this->addError('paymentTermId', 'Escolha a condição de pagamento.');

            return;
        }

        try {
            $version = $submit->handle(new QuoteVersionData(
                quoteId: $this->quoteId,
                reviewed: new ReviewedQuoteState($this->withBuyerVersionId, $this->preparingVersionId),
                idempotencyKey: $this->submitKey,
                lines: $this->inputs(),
                paymentTermId: $this->paymentTermId,
                validUntilDay: $this->validUntilDay,
                stockHoldUntilDay: trim($this->stockHoldUntilDay) !== '' ? $this->stockHoldUntilDay : null,
                notes: $this->notes,
                justification: $this->justification,
                selfApprove: $this->selfApprove,
                expectedTotalCents: $this->expectedTotalCents,
            ));
        } catch (BusinessRuleViolation $e) {
            $this->submitKey = (string) Str::uuid7();
            $this->addError(match ($e->details()['field'] ?? null) {
                'justification' => 'justification', 'valid_until' => 'validUntilDay', 'stock_hold_until' => 'stockHoldUntilDay',
                'payment_term' => 'paymentTermId', 'notes' => 'notes', default => 'form',
            }, $e->getMessage());

            return;
        }

        session()->flash('status', $version->status->isPreparing() ? 'Versão enviada para aprovação comercial.' : 'Proposta enviada ao comprador.');
        $this->redirectRoute('quotes.show', ['quote' => $this->quoteId]);
    }

    public function render(StaffQuoteQuery $quotes, QuotePricing $pricing, CommercialEvaluator $evaluator, Access $access): View
    {
        $quote = $quotes->find($this->quoteId)->load('buyerCompany');
        $state = ReviewedQuoteState::of(QuoteVersion::query()->where('quote_id', $quote->id)->get());
        $this->withBuyerVersionId = $state->withBuyerVersionId;
        $this->preparingVersionId = $state->preparingVersionId;

        [$priced, $totals, $review, $previewError] = $this->preview($quote, $pricing, $evaluator, $access);
        $this->expectedTotalCents = $totals?->total->cents;

        return view('livewire.staff.quotes.quote-version-form', [
            'quote' => $quote,
            'priced' => $priced,
            'totals' => $totals,
            'review' => $review,
            'previewError' => $previewError,
            'terms' => PaymentTerm::query()->whereIn('id', BuyerPaymentTerm::query()->where('buyer_company_id', $quote->buyer_company_id)->select('payment_term_id'))
                ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'selfApprovable' => $review !== null && $review->requiresApproval() && $review->selfApprovable($access->can(Permission::PricingApprove)),
            'replacing' => $state->preparingVersionId !== null,
        ])->title("Nova versão · {$quote->number}");
    }

    /**
     * @return array{0: list<PricedQuoteLine>, 1: \App\Domain\Ordering\DocumentTotals|null, 2: CommercialReview|null, 3: string|null}
     */
    private function preview(Quote $quote, QuotePricing $pricing, CommercialEvaluator $evaluator, Access $access): array
    {
        try {
            [$priced, $totals] = $pricing->price(BuyerCompany::query()->findOrFail($quote->buyer_company_id), $this->inputs());

            return [$priced, $totals, $evaluator->evaluate($priced, Percentage::ofBasisPoints($access->maxDiscountBps())), null];
        } catch (BusinessRuleViolation $e) {
            return [[], null, null, $e->getMessage()];
        }
    }

    /**
     * @return list<QuoteLineInput>
     */
    private function inputs(): array
    {
        $inputs = [];
        $codes = [];

        foreach (array_slice($this->lines, 0, (int) config('quotes.max_lines')) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $codes[$index] = mb_substr(trim((string) ($line['sku'] ?? '')), 0, 60);
        }

        $skus = Sku::query()->whereIn('code', array_values($codes))->pluck('id', 'code')->all();

        foreach ($codes as $index => $code) {
            $line = $this->lines[$index];
            $skuId = $skus[$code] ?? throw new InvalidQuote("SKU {$code} não encontrado.", ['field' => 'lines']);

            try {
                $inputs[] = new QuoteLineInput(
                    (string) $skuId,
                    Quantity::parseLocalized(mb_substr((string) ($line['quantity'] ?? ''), 0, 21)),
                    Percentage::parse(mb_substr((string) ($line['discount'] ?? '0'), 0, 6) ?: '0'),
                );
            } catch (InvalidArgumentException) {
                throw new InvalidQuote("Quantidade ou desconto inválido na linha do SKU {$code}.", ['field' => 'lines']);
            }
        }

        return $inputs;
    }

    /**
     * @return list<string>
     */
    public static function violationLabels(CommercialReview $review): array
    {
        return array_map(static fn (DiscountViolation $v): string => $v->label(), $review->violations());
    }
}
