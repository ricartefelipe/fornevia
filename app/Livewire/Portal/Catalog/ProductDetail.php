<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Catalog;

use App\Actions\Ordering\Portal\AddToCart;
use App\Data\Catalog\Portal\CatalogSku;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Services\Catalog\BuyerCatalog;
use App\Services\Catalog\PurchaseQuantityAdvisor;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Página de produto do portal: SKUs ativos, conferência da quantidade pela
 * regra de compra (mínimo, múltiplo, casas decimais) com a quantidade
 * válida mais próxima e o preço resolvido para ela, galeria de imagens e
 * documentos. O produto é recarregado e a permissão conferida a cada
 * renderização pelo BuyerCatalog.
 *
 * "Adicionar ao carrinho" usa a quantidade conferida pelo advisor (a
 * mínima de compra quando nada foi digitado); AddToCart confere de novo
 * visibilidade, regra de compra e preço no servidor.
 */
#[Layout('layouts.app')]
#[Title('Produto')]
final class ProductDetail extends Component
{
    private const int MAX_TRACKED_SKUS = 200;

    #[Locked]
    public string $productId = '';

    /** @var array<string, string> quantidade digitada por SKU (entrada não confiável) */
    public array $quantities = [];

    #[Locked]
    public ?string $cartMessage = null;

    public function mount(string $product): void
    {
        $this->productId = UntrustedId::uuid($product, Product::class);
    }

    /**
     * Mantém apenas entradas com chave de SKU e texto de tamanho limitado.
     */
    public function updatedQuantities(): void
    {
        $sanitized = [];

        foreach ($this->quantities as $skuId => $value) {
            if (count($sanitized) >= self::MAX_TRACKED_SKUS) {
                break;
            }

            if (is_string($skuId) && Str::isUuid($skuId) && is_string($value)) {
                $sanitized[strtolower($skuId)] = mb_substr($value, 0, PurchaseQuantityAdvisor::MAX_INPUT_LENGTH + 1);
            }
        }

        $this->quantities = $sanitized;
    }

    /**
     * Substitui a quantidade digitada pela quantidade válida mais próxima.
     */
    public function applySuggestion(string $skuId, BuyerCatalog $catalog): void
    {
        $id = UntrustedId::uuid($skuId, Sku::class);
        $detail = $catalog->product($this->productId, $this->quantities);
        $sku = array_find($detail->skus, static fn (CatalogSku $candidate): bool => $candidate->id === $id);

        if ($sku === null) {
            throw (new ModelNotFoundException)->setModel(Sku::class, [$id]);
        }

        $suggestion = $sku->feedback?->suggestion;

        if ($suggestion !== null) {
            $this->quantities[$sku->id] = $suggestion->format();
        }
    }

    /**
     * Adiciona o SKU ao carrinho na quantidade conferida (somada à que já
     * estiver no carrinho).
     */
    public function addToCart(string $skuId, BuyerCatalog $catalog, AddToCart $add): void
    {
        $id = UntrustedId::uuid($skuId, Sku::class);
        $detail = $catalog->product($this->productId, $this->quantities);
        $sku = array_find($detail->skus, static fn (CatalogSku $candidate): bool => $candidate->id === $id);

        if ($sku === null) {
            throw (new ModelNotFoundException)->setModel(Sku::class, [$id]);
        }

        $this->cartMessage = null;
        $this->resetErrorBag("cart.{$sku->id}");
        $feedback = $sku->feedback;

        if ($feedback !== null && (! $feedback->valid || $feedback->requested === null)) {
            $this->addError("cart.{$sku->id}", 'Corrija a quantidade antes de adicionar ao carrinho.');

            return;
        }

        $quantity = $feedback->requested ?? $sku->quantity;

        try {
            $add->handle($sku->id, $quantity);
        } catch (InvalidCartItem $e) {
            $this->addError("cart.{$sku->id}", $e->getMessage());

            return;
        }

        $this->cartMessage = "{$quantity->format()} {$sku->unitCode} de {$sku->code} adicionado ao carrinho.";
    }

    public function render(BuyerCatalog $catalog, Access $access): View
    {
        $detail = $catalog->product($this->productId, $this->quantities);

        return view('livewire.portal.catalog.product-detail', [
            'product' => $detail,
            'canAddToCart' => $access->can(Permission::PortalCart),
        ])->title($detail->name);
    }
}
