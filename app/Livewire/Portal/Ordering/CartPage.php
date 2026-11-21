<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Ordering;

use App\Actions\Ordering\PlaceOrder;
use App\Actions\Ordering\Portal\CheckoutOptions;
use App\Actions\Ordering\Portal\CurrentCart;
use App\Actions\Ordering\Portal\RemoveCartItem;
use App\Actions\Ordering\Portal\UpdateCartItem;
use App\Data\Ordering\CartLineQuote;
use App\Data\Ordering\PlaceOrderData;
use App\Data\Ordering\Portal\CartView;
use App\Data\Ordering\Portal\CheckoutChoices;
use App\Domain\Approvals\ApprovalContext;
use App\Domain\Approvals\BudgetPosition;
use App\Domain\Ordering\Exceptions\CartAlreadyConverted;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Services\Approvals\ApprovalRuleResolver;
use App\Services\Approvals\BudgetLedger;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Idempotency\IdempotencyKeyReused;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Carrinho do comprador e checkout (RF-060, RF-061, RF-063).
 *
 * - Linhas, problemas e totais são recalculados no servidor a cada
 *   renderização (CurrentCart → CartPricing).
 * - A confirmação usa o carrinho e o total exibidos na última renderização
 *   (propriedades bloqueadas): se os valores mudaram, PlaceOrder recusa e a
 *   tela mostra o novo total.
 * - A chave de idempotência é gerada no servidor para cada tentativa de
 *   envio: reenvios da mesma tentativa (duplo clique, nova tentativa após
 *   falha de rede) devolvem o mesmo pedido; após uma recusa, a próxima
 *   tentativa recebe outra chave.
 * - Com o carrinho pronto, a tela antecipa a regra de aprovação interna
 *   que se aplicaria (ApprovalRuleResolver, com os mesmos critérios do
 *   checkout) e o orçamento disponível do centro de custo no mês. É só uma
 *   prévia: PlaceOrder decide de novo na confirmação. Orçamento
 *   insuficiente (BudgetExceeded) vira erro do campo de centro de custo.
 */
#[Layout('layouts.app')]
#[Title('Carrinho')]
final class CartPage extends Component
{
    private const int MAX_TRACKED_LINES = 500;

    #[Locked]
    public string $checkoutKey = '';

    #[Locked]
    public ?string $displayedCartId = null;

    #[Locked]
    public ?int $displayedTotalCents = null;

    /** @var array<string, string> quantidade digitada por linha do carrinho (entrada não confiável) */
    public array $quantities = [];

    public string $unitId = '';

    public string $shippingAddressId = '';

    public string $billingAddressId = '';

    public string $costCenterId = '';

    public string $paymentTermId = '';

    public string $purchaseOrderReference = '';

    public string $notes = '';

    #[Locked]
    public ?string $message = null;

    public function mount(CheckoutOptions $options): void
    {
        $this->checkoutKey = self::newKey();
        $choices = $options->handle();
        $this->shippingAddressId = (string) $choices->defaultShippingAddressId;
        $this->billingAddressId = (string) $choices->defaultBillingAddressId;
        $this->paymentTermId = (string) $choices->defaultPaymentTermId;
    }

    /**
     * Mantém apenas entradas com chave de linha e texto de tamanho limitado.
     */
    public function updatedQuantities(): void
    {
        $sanitized = [];

        foreach ($this->quantities as $itemId => $value) {
            if (count($sanitized) >= self::MAX_TRACKED_LINES) {
                break;
            }

            if (is_string($itemId) && Str::isUuid($itemId) && is_string($value)) {
                $sanitized[strtolower($itemId)] = mb_substr($value, 0, 21);
            }
        }

        $this->quantities = $sanitized;
    }

    /**
     * Unidade trocada: seleções que deixaram de valer para ela voltam ao padrão.
     */
    public function updatedUnitId(): void
    {
        $this->unitId = strtolower($this->unitId);
        $choices = app(CheckoutOptions::class)->handle($this->unitId !== '' ? $this->unitId : null);

        if ($this->unitId !== '' && ! $choices->hasUnit($this->unitId)) {
            $this->unitId = '';
        }

        if ($this->shippingAddressId !== '' && ! $choices->hasShippingAddress($this->shippingAddressId)) {
            $this->shippingAddressId = (string) $choices->defaultShippingAddressId;
        }

        if ($this->billingAddressId !== '' && ! $choices->hasBillingAddress($this->billingAddressId)) {
            $this->billingAddressId = (string) $choices->defaultBillingAddressId;
        }

        if ($this->costCenterId !== '' && ! $choices->hasCostCenter($this->costCenterId)) {
            $this->costCenterId = '';
        }
    }

    public function updateQuantity(string $itemId, UpdateCartItem $update): void
    {
        $this->message = null;
        $key = strtolower($itemId);
        $input = $this->quantities[$key] ?? '';

        try {
            $item = $update->handle($itemId, $input);
        } catch (InvalidCartItem $e) {
            $this->addError("quantities.{$key}", $e->getMessage());

            return;
        }

        $this->resetErrorBag("quantities.{$key}");
        $this->quantities[$item->id] = $item->quantity->format();
        $this->message = 'Quantidade atualizada.';
    }

    public function removeItem(string $itemId, RemoveCartItem $remove): void
    {
        $remove->handle($itemId);

        unset($this->quantities[strtolower($itemId)]);
        $this->resetErrorBag();
        $this->message = 'Item removido do carrinho.';
    }

    public function placeOrder(PlaceOrder $place, CheckoutOptions $options): void
    {
        $this->message = null;
        $this->resetErrorBag();

        if ($this->displayedCartId === null || $this->displayedTotalCents === null) {
            $this->addError('checkout', 'Revise os itens do carrinho antes de confirmar.');

            return;
        }

        $choices = $options->handle($this->unitId !== '' ? $this->unitId : null);

        if (! $this->validCheckout($choices)) {
            return;
        }

        try {
            $order = $place->handle(new PlaceOrderData(
                cartId: $this->displayedCartId,
                idempotencyKey: $this->checkoutKey,
                shippingAddressId: $this->shippingAddressId,
                paymentTermId: $this->paymentTermId,
                billingAddressId: self::nullable($this->billingAddressId),
                buyerUnitId: self::nullable($this->unitId),
                costCenterId: self::nullable($this->costCenterId),
                purchaseOrderReference: self::nullable(self::singleLine($this->purchaseOrderReference)),
                notes: self::nullable(trim($this->notes)),
                expectedTotalCents: $this->displayedTotalCents,
            ));
        } catch (BusinessRuleViolation $e) {
            // Nova tentativa, nova chave: a recusa desfez a anterior por completo.
            $this->checkoutKey = self::newKey();
            $this->addError(($e->details()['field'] ?? null) === 'cost_center' ? 'costCenterId' : 'checkout', match (true) {
                $e instanceof CartAlreadyConverted => $e->getMessage().' Consulte-o em Pedidos.',
                $e instanceof IdempotencyKeyReused => 'Este envio já foi processado com outros dados. Confira o pedido em Pedidos antes de tentar novamente.',
                default => $e->getMessage(),
            });

            return;
        }

        $this->redirectRoute('portal.orders.confirmation', ['order' => $order->id]);
    }

    public function render(CurrentCart $current, CheckoutOptions $options, Access $access): View
    {
        $cart = $current->handle();

        // Carrinho e total desta exibição: a confirmação só vale para eles.
        $this->displayedCartId = $cart->cartId;
        $this->displayedTotalCents = $cart->isReady() ? $cart->totals?->total->cents : null;

        foreach ($cart->lines as $line) {
            $this->quantities[$line->cartItemId] ??= $line->quantity->format();
        }

        $choices = $options->handle($this->unitId !== '' ? $this->unitId : null);
        $costCenterId = $this->costCenterId !== '' && $choices->hasCostCenter($this->costCenterId) ? strtolower($this->costCenterId) : null;

        return view('livewire.portal.ordering.cart-page', [
            'cart' => $cart,
            'choices' => $choices,
            'canPlace' => $access->can(Permission::PortalOrdersPlace),
            'approval' => $cart->isReady() ? $this->approvalPreview($cart, $choices, $costCenterId, $access) : null,
            'budget' => $costCenterId !== null ? $this->budgetPosition($costCenterId) : null,
        ]);
    }

    /**
     * Regra de aprovação interna que se aplicaria ao carrinho com as escolhas
     * atuais (total, centro de custo, unidade, categorias e perfil).
     *
     * @return array{rule: string, levels: int}|null
     */
    private function approvalPreview(CartView $cart, CheckoutChoices $choices, ?string $costCenterId, Access $access): ?array
    {
        $membership = $access->membershipOrNull();
        $companyId = $access->buyerCompanyId();

        if ($cart->totals === null || $membership === null || $companyId === null) {
            return null;
        }

        $skuIds = array_values(array_unique(array_map(static fn (CartLineQuote $line): string => $line->skuId, $cart->lines)));
        $categoryIds = DB::table('skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->whereIn('skus.id', $skuIds)
            ->whereNotNull('products.category_id')
            ->distinct()
            ->pluck('products.category_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $rule = app(ApprovalRuleResolver::class)->resolve($companyId, new ApprovalContext(
            total: $cart->totals->total,
            costCenterId: $costCenterId,
            buyerUnitId: $this->unitId !== '' && $choices->hasUnit($this->unitId) ? strtolower($this->unitId) : null,
            categoryIds: array_values($categoryIds),
            requesterRoleKey: $membership->role->key,
        ));

        return $rule === null ? null : ['rule' => $rule->name, 'levels' => $rule->levels->count()];
    }

    /**
     * Orçamento do centro de custo no mês em que o pedido seria gravado
     * (o mesmo critério de BudgetLedger); null quando não há orçamento.
     */
    private function budgetPosition(string $costCenterId): ?BudgetPosition
    {
        $companyId = app(Access::class)->buyerCompanyId();

        return $companyId === null ? null : app(BudgetLedger::class)->budgetFor($companyId, $costCenterId, app(Clock::class)->now())?->position();
    }

    private function validCheckout(CheckoutChoices $choices): bool
    {
        $valid = true;
        $fail = function (string $field, string $message) use (&$valid): void {
            $this->addError($field, $message);
            $valid = false;
        };

        if ($this->shippingAddressId === '' || ! $choices->hasShippingAddress($this->shippingAddressId)) {
            $fail('shippingAddressId', 'Selecione um endereço de entrega da sua empresa.');
        }

        if ($this->paymentTermId === '' || ! $choices->hasPaymentTerm($this->paymentTermId)) {
            $fail('paymentTermId', 'Selecione uma condição de pagamento disponível para a sua empresa.');
        }

        if ($this->billingAddressId !== '' && ! $choices->hasBillingAddress($this->billingAddressId)) {
            $fail('billingAddressId', 'Selecione um endereço de cobrança da sua empresa.');
        }

        if ($this->unitId !== '' && ! $choices->hasUnit($this->unitId)) {
            $fail('unitId', 'Selecione uma unidade da sua empresa.');
        }

        if ($this->costCenterId !== '' && ! $choices->hasCostCenter($this->costCenterId)) {
            $fail('costCenterId', 'Selecione um centro de custo da sua empresa.');
        }

        $reference = self::singleLine($this->purchaseOrderReference);

        if (mb_strlen($reference) > 60) {
            $fail('purchaseOrderReference', 'A referência da ordem de compra deve ter até 60 caracteres.');
        }

        if (mb_strlen(trim($this->notes)) > 1000) {
            $fail('notes', 'A observação deve ter até 1.000 caracteres.');
        }

        return $valid;
    }

    private static function newKey(): string
    {
        return 'portal-checkout:'.Str::uuid7()->toString();
    }

    private static function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Texto de uma linha: sem quebras nem caracteres de controle.
     */
    private static function singleLine(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $value));
    }
}
