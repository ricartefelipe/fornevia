<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal\Support;

use App\Data\Ordering\CartLineQuote;
use App\Data\Ordering\CartQuote;
use App\Data\Ordering\Portal\BuyerActor;
use App\Data\Ordering\Portal\CartAdditionLine;
use App\Data\Ordering\Portal\CartAdditionReport;
use App\Domain\Catalog\PurchaseRule;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Domain\Ordering\LineAmounts;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;
use App\Enums\Ordering\CartStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use App\Models\Ordering\Cart;
use App\Models\Ordering\CartItem;
use App\Services\Catalog\PurchaseQuantityAdvisor;
use App\Services\Ordering\CartPricing;
use App\Services\Pricing\PricingService;
use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gravação de itens no carrinho aberto da associação.
 *
 * - O carrinho é bloqueado (FOR UPDATE) antes de qualquer item: inclusões
 *   concorrentes do mesmo usuário (outra aba, duplo clique) são
 *   serializadas, e a ordem de locks começa pelo documento (ADR-006).
 * - A quantidade final da linha (inclusive somada a uma já existente) é
 *   conferida pela regra de compra, pelo limite por linha, pelo preço
 *   vigente e pelo limite de cálculo do total. Preços nunca são gravados:
 *   o carrinho é sempre recalculado no servidor (CartPricing).
 * - Disponibilidade de estoque é informativa: a reserva só acontece na
 *   confirmação do pedido.
 */
final readonly class CartWriter
{
    public function __construct(
        private PricingService $pricing,
        private CartPricing $cartPricing,
        private PurchaseQuantityAdvisor $advisor,
        private CurrentContext $context,
        private Clock $clock,
    ) {}

    /**
     * Carrinho aberto da associação, bloqueado. Quando não existe e $create
     * é verdadeiro, é criado; a criação concorrente é resolvida pelo índice
     * único parcial (um carrinho aberto por associação).
     */
    public function lockOpenCart(BuyerActor $actor, bool $create = true): ?Cart
    {
        $cart = $this->findOpen($actor);

        if ($cart !== null || ! $create) {
            return $cart;
        }

        $now = $this->clock->now();

        DB::insert(
            "insert into carts (id, tenant_id, buyer_company_id, membership_id, status, created_at, updated_at)
             values (?, ?, ?, ?, 'open', ?, ?)
             on conflict (tenant_id, membership_id) where status = 'open' do nothing",
            [(string) Str::uuid7(), $this->context->tenantId(), $actor->company->id, $actor->membership->id, $now, $now],
        );

        return $this->findOpen($actor) ?? throw new RuntimeException('Carrinho aberto não encontrado após a criação.');
    }

    /**
     * Quantidade digitada (pt-BR) conferida pela regra de compra do SKU.
     *
     * @throws InvalidCartItem
     */
    public function parseQuantity(Sku $sku, string $input, string $field = 'quantity'): Quantity
    {
        $feedback = $this->advisor->evaluate($sku->purchaseRule(), $input, $sku->salesUnit->code);

        if ($feedback->valid && $feedback->requested !== null) {
            return $feedback->requested;
        }

        throw InvalidCartItem::quantity($sku->code, $feedback->messages, $feedback->suggestion, $sku->salesUnit->code, $field);
    }

    /**
     * Soma a quantidade à linha do SKU (ou cria a linha).
     *
     * @throws InvalidCartItem
     */
    public function add(Cart $cart, BuyerCompany $company, Sku $sku, Quantity $quantity): CartItem
    {
        $item = CartItem::query()->where('cart_id', $cart->id)->where('sku_id', $sku->id)->first();
        $total = $item === null ? $quantity : $item->quantity->plus($quantity);

        if ($item !== null) {
            $this->assertWithinLineLimit($sku, $total, 'A quantidade somada à já existente no carrinho');
        }

        $this->assertPurchasable($company, $sku, $total);

        return $this->store($cart, $item, $sku, $total);
    }

    /**
     * Substitui a quantidade de uma linha existente.
     *
     * @throws InvalidCartItem
     */
    public function replace(Cart $cart, BuyerCompany $company, CartItem $item, Sku $sku, Quantity $quantity): CartItem
    {
        $this->assertPurchasable($company, $sku, $quantity);

        return $this->store($cart, $item, $sku, $quantity);
    }

    /**
     * Regra de compra, limite por linha, preço vigente e total calculável.
     *
     * @throws InvalidCartItem
     */
    public function assertPurchasable(BuyerCompany $company, Sku $sku, Quantity $quantity): void
    {
        $unit = $sku->salesUnit->code;

        if (! $sku->isSellable()) {
            throw InvalidCartItem::because("O item {$sku->code} não está disponível para compra.", $sku->code);
        }

        $this->assertWithinLineLimit($sku, $quantity, 'A quantidade');

        if (! $sku->purchaseRule()->accepts($quantity)) {
            $feedback = $this->advisor->evaluate($sku->purchaseRule(), $quantity->format(), $unit);

            throw InvalidCartItem::quantity($sku->code, $feedback->messages, $feedback->suggestion, $unit);
        }

        $price = $this->pricing->resolve($company, [$sku->id => $quantity])[$sku->id] ?? null;

        if ($price === null) {
            throw InvalidCartItem::because("O item {$sku->code} está sem preço vigente para a sua empresa. Solicite uma cotação ao seu representante.", $sku->code);
        }

        try {
            LineAmounts::calculate($price->unitPrice, Percentage::zero(), $quantity);
        } catch (AmountOutOfRange) {
            throw InvalidCartItem::because("O total do item {$sku->code} excede o limite de cálculo. Reduza a quantidade.", $sku->code);
        }
    }

    /**
     * Quantidade válida mais próxima (maior ou igual) pela regra atual do
     * SKU, para itens vindos de lista ou de pedido anterior; null quando não
     * há quantidade válida representável.
     */
    public function nearestValid(Sku $sku, Quantity $quantity): ?Quantity
    {
        $rule = $sku->purchaseRule();

        if ($rule->accepts($quantity)) {
            return $quantity;
        }

        $suggestion = $rule->nearestValid($quantity->isPositive() ? $quantity : $rule->minimum);

        return $rule->accepts($suggestion) && ! $suggestion->greaterThan(Quantity::of(PurchaseRule::MAX_QUANTITY)) ? $suggestion : null;
    }

    /**
     * Inclui no carrinho um item vindo de lista de compra ou de pedido
     * anterior: SKU indisponível é informado; quantidade fora da regra de
     * compra vigente é ajustada para a válida mais próxima (e informada).
     */
    public function addFromSource(Cart $cart, BuyerCompany $company, ?Sku $sku, Quantity $requested, string $input, ?string $code = null, ?string $name = null): CartAdditionLine
    {
        if ($sku === null) {
            return CartAdditionLine::rejected(null, $input, 'O item não está mais disponível para a sua empresa.', $code, $name);
        }

        $unit = $sku->salesUnit->code;
        $quantity = $this->nearestValid($sku, $requested);

        if ($quantity === null) {
            return CartAdditionLine::rejected(null, $input, 'A quantidade não pode ser ajustada à regra de compra atual do item.', $sku->code, $sku->product->name, null, $unit);
        }

        try {
            $this->add($cart, $company, $sku, $quantity);
        } catch (InvalidCartItem $e) {
            return new CartAdditionLine(null, $input, CartAdditionLine::REJECTED, null, $sku->code, $sku->product->name, null, $unit, $e->messages(), $e->suggestion());
        }

        $adjusted = ! $quantity->equals($requested);

        return new CartAdditionLine(
            null, $input, $adjusted ? CartAdditionLine::ADJUSTED : CartAdditionLine::ADDED, $sku->id, $sku->code, $sku->product->name, $quantity, $unit,
            $adjusted ? ["Quantidade ajustada de {$requested->format()} para {$quantity->format()} {$unit} pela regra de compra atual."] : [],
        );
    }

    /**
     * Carrinho recalculado no servidor; total fora do intervalo suportado
     * é recusado (desfaz a operação que o provocou).
     *
     * @throws InvalidCartItem
     */
    public function quote(Cart $cart, BuyerCompany $company): CartQuote
    {
        try {
            return $this->cartPricing->quote($cart, $company);
        } catch (AmountOutOfRange) {
            throw InvalidCartItem::because('O total do carrinho excederia o limite de cálculo. Reduza as quantidades.', null, 'cart');
        }
    }

    /**
     * Recalcula o carrinho após uma inclusão em lote e acrescenta às linhas
     * adicionadas os avisos de disponibilidade vigentes.
     *
     * @param  list<CartAdditionLine>  $lines
     *
     * @throws InvalidCartItem
     */
    public function report(Cart $cart, BuyerCompany $company, array $lines): CartAdditionReport
    {
        if (array_filter($lines, static fn (CartAdditionLine $line): bool => $line->isAdded()) === []) {
            return new CartAdditionReport($lines);
        }

        $quote = $this->quote($cart, $company);
        $bySku = [];

        foreach ($quote->lines as $quoted) {
            $bySku[$quoted->skuId] = $quoted;
        }

        return new CartAdditionReport(array_map(static function (CartAdditionLine $line) use ($bySku): CartAdditionLine {
            $quoted = $line->isAdded() && $line->skuId !== null ? ($bySku[$line->skuId] ?? null) : null;

            return $quoted === null ? $line : $line->withWarnings(self::availabilityWarnings($quoted));
        }, $lines));
    }

    /**
     * @return list<string>
     */
    public static function availabilityWarnings(CartLineQuote $line): array
    {
        return match ($line->availability) {
            'unavailable' => ['Estoque insuficiente no momento: ajuste a quantidade ou remova o item antes de confirmar.'],
            'backorder' => ['Estoque insuficiente no momento: o que faltar será atendido sob encomenda.'],
            default => [],
        };
    }

    private function findOpen(BuyerActor $actor): ?Cart
    {
        return Cart::query()
            ->where('membership_id', $actor->membership->id)
            ->where('buyer_company_id', $actor->company->id)
            ->where('status', CartStatus::Open->value)
            ->lockForUpdate()
            ->first();
    }

    private function assertWithinLineLimit(Sku $sku, Quantity $quantity, string $subject): void
    {
        $max = Quantity::of(PurchaseRule::MAX_QUANTITY);

        if ($quantity->greaterThan($max)) {
            throw InvalidCartItem::because("{$subject} do item {$sku->code} excede o limite de {$max->format()} {$sku->salesUnit->code} por item.", $sku->code);
        }
    }

    private function store(Cart $cart, ?CartItem $item, Sku $sku, Quantity $quantity): CartItem
    {
        if ($item === null) {
            $item = new CartItem;
            $item->forceFill([
                'cart_id' => $cart->id,
                'buyer_company_id' => $cart->buyer_company_id,
                'sku_id' => $sku->id,
            ]);
        }

        $item->forceFill(['quantity' => $quantity])->save();
        $cart->touch();

        return $item;
    }
}
