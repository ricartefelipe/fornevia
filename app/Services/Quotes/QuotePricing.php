<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Data\Quotes\PricedQuoteLine;
use App\Data\Quotes\QuoteLineInput;
use App\Domain\Ordering\DocumentTotals;
use App\Domain\Ordering\LineAmounts;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use App\Services\Ordering\PurchaseEligibility;
use App\Services\Pricing\PricingService;
use InvalidArgumentException;

/**
 * Precificação da proposta no servidor (RS-030): itens visíveis para a
 * empresa, vendáveis e dentro das regras de compra (RN-020), preço de
 * referência resolvido pelo PricingService (precedência e faixas por
 * quantidade) e desconto informado pela equipe. A mesma classe alimenta a
 * prévia da tela e a submissão.
 */
final readonly class QuotePricing
{
    public function __construct(
        private PricingService $pricing,
        private PurchaseEligibility $eligibility,
    ) {}

    /**
     * @param  list<QuoteLineInput>  $inputs
     * @return array{0: list<PricedQuoteLine>, 1: DocumentTotals}
     */
    public function price(BuyerCompany $buyer, array $inputs): array
    {
        $max = (int) config('quotes.max_lines');

        if ($inputs === [] || count($inputs) > $max) {
            throw new InvalidQuote("A proposta precisa ter de 1 a {$max} itens.", ['field' => 'lines']);
        }

        $skuIds = array_map(static fn (QuoteLineInput $line): string => $line->skuId, $inputs);

        if (count(array_unique($skuIds)) !== count($skuIds)) {
            throw new InvalidQuote('Cada SKU pode aparecer uma única vez na proposta.', ['field' => 'lines']);
        }

        $skus = Sku::query()->with(['product', 'salesUnit'])->whereKey($skuIds)->get()->keyBy('id');
        $issue = $this->eligibility->itemsIssue($buyer, array_map(static fn (QuoteLineInput $line): array => [
            'sku_id' => $line->skuId,
            'sku_code' => $skus->get($line->skuId)->code ?? $line->skuId,
            'quantity' => $line->quantity,
        ], $inputs));

        if ($issue !== null) {
            throw new InvalidQuote($issue, ['field' => 'lines']);
        }

        $prices = $this->pricing->resolve($buyer, array_combine($skuIds, array_map(static fn (QuoteLineInput $line) => $line->quantity, $inputs)));
        $lines = [];

        foreach (array_values($inputs) as $index => $input) {
            /** @var Sku $sku */
            $sku = $skus->get($input->skuId);
            $price = $prices[$sku->id] ?? null;

            if ($price === null) {
                throw new InvalidQuote("O item {$sku->code} não tem preço vigente para a empresa: cadastre o preço antes de cotar.", ['field' => 'lines']);
            }

            if ($input->discount->basisPoints > 9_999) {
                throw new InvalidQuote("O desconto do item {$sku->code} deve ser menor que 100%.", ['field' => 'lines']);
            }

            try {
                $base = $sku->toBaseQuantity($input->quantity);
            } catch (InvalidArgumentException) {
                throw new InvalidQuote("Conversão de unidade inválida para a quantidade do item {$sku->code}.", ['field' => 'lines']);
            }

            $amounts = LineAmounts::calculate($price->unitPrice, $input->discount, $input->quantity);

            if (! $amounts->unitNetPrice->isPositive() || ! $amounts->total->isPositive()) {
                throw new InvalidQuote("O preço líquido do item {$sku->code} precisa ser maior que zero.", ['field' => 'lines']);
            }

            $lines[] = new PricedQuoteLine($index + 1, $sku, $base, $price, $amounts);
        }

        return [$lines, DocumentTotals::of(array_map(static fn (PricedQuoteLine $line): LineAmounts => $line->amounts, $lines))];
    }
}
