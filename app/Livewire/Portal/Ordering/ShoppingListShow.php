<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Ordering;

use App\Actions\Ordering\Portal\AddListToCart;
use App\Actions\Ordering\Portal\DeleteShoppingList;
use App\Actions\Ordering\Portal\Exceptions\InvalidCartItem;
use App\Actions\Ordering\Portal\Exceptions\InvalidShoppingList;
use App\Actions\Ordering\Portal\RemoveShoppingListItem;
use App\Actions\Ordering\Portal\SaveShoppingList;
use App\Actions\Ordering\Portal\SaveShoppingListItem;
use App\Actions\Ordering\Portal\ShoppingLists;
use App\Models\Catalog\Sku;
use App\Models\Ordering\ShoppingListItem;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Página de uma lista de compra: itens com quantidade, inclusão por código
 * do SKU, alteração e remoção (somente por quem criou a lista), renomear,
 * compartilhar, excluir e enviar a lista ao carrinho.
 */
#[Layout('layouts.app')]
#[Title('Lista de compra')]
final class ShoppingListShow extends Component
{
    private const int MAX_TRACKED_ITEMS = 500;

    #[Locked]
    public string $listId = '';

    public string $name = '';

    public bool $shared = false;

    public string $skuCode = '';

    public string $quantity = '';

    /** @var array<string, string> quantidade digitada por item da lista (entrada não confiável) */
    public array $quantities = [];

    #[Locked]
    public ?string $message = null;

    #[Locked]
    public ?string $failure = null;

    /** @var array{summary: string, added: int, rejected: int, lines: list<array<string, mixed>>}|null */
    #[Locked]
    public ?array $report = null;

    public function mount(string $list, ShoppingLists $lists): void
    {
        $found = $lists->find($lists->actor(), $list);

        $this->listId = $found->id;
        $this->name = $found->name;
        $this->shared = $found->is_shared;
    }

    /**
     * Mantém apenas entradas com chave de item e texto de tamanho limitado.
     */
    public function updatedQuantities(): void
    {
        $sanitized = [];

        foreach ($this->quantities as $itemId => $value) {
            if (count($sanitized) >= self::MAX_TRACKED_ITEMS) {
                break;
            }

            if (is_string($itemId) && Str::isUuid($itemId) && is_string($value)) {
                $sanitized[strtolower($itemId)] = mb_substr($value, 0, 21);
            }
        }

        $this->quantities = $sanitized;
    }

    public function rename(SaveShoppingList $save): void
    {
        $this->clearFeedback();

        try {
            $list = $save->handle($this->listId, $this->name, $this->shared);
        } catch (InvalidShoppingList $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->name = $list->name;
        $this->message = 'Lista atualizada.';
    }

    public function addItem(SaveShoppingListItem $save): void
    {
        $this->clearFeedback();

        try {
            $item = $save->handle($this->listId, $this->skuCode, $this->quantity);
        } catch (InvalidCartItem|InvalidShoppingList $e) {
            $field = $e instanceof InvalidCartItem && $e->field() === 'quantity' ? 'quantity' : 'skuCode';
            $this->addError($field, $e->getMessage());

            return;
        }

        $this->quantities[$item->id] = $item->quantity->format();
        $this->message = mb_strtoupper(trim($this->skuCode)).' incluído na lista.';
        $this->reset('skuCode', 'quantity');
    }

    public function updateItem(string $itemId, ShoppingLists $lists, SaveShoppingListItem $save): void
    {
        $this->clearFeedback();
        $item = $this->item($lists, $itemId);
        $key = "quantities.{$item->id}";

        /** @var Sku|null $sku */
        $sku = $item->sku;

        if ($sku === null) {
            $this->addError($key, 'Este item não está mais disponível para a sua empresa. Remova-o da lista.');

            return;
        }

        try {
            $saved = $save->handle($this->listId, $sku->code, $this->quantities[$item->id] ?? '');
        } catch (InvalidCartItem|InvalidShoppingList $e) {
            $this->addError($key, $e->getMessage());

            return;
        }

        $this->quantities[$saved->id] = $saved->quantity->format();
        $this->message = "Quantidade de {$sku->code} atualizada.";
    }

    public function removeItem(string $itemId, RemoveShoppingListItem $remove): void
    {
        $this->clearFeedback();

        try {
            $remove->handle($this->listId, $itemId);
        } catch (InvalidShoppingList $e) {
            $this->failure = $e->getMessage();

            return;
        }

        unset($this->quantities[strtolower($itemId)]);
        $this->message = 'Item removido da lista.';
    }

    public function delete(DeleteShoppingList $delete): void
    {
        $this->clearFeedback();

        try {
            $name = $delete->handle($this->listId);
        } catch (InvalidShoppingList $e) {
            $this->failure = $e->getMessage();

            return;
        }

        session()->flash('success', "Lista {$name} excluída.");
        $this->redirectRoute('portal.lists');
    }

    public function addToCart(AddListToCart $add): void
    {
        $this->clearFeedback();

        try {
            $this->report = $add->handle($this->listId)->toDisplay();
        } catch (InvalidCartItem $e) {
            $this->failure = $e->getMessage();
        }
    }

    public function render(ShoppingLists $lists): View
    {
        $actor = $lists->actor();
        $list = $lists->find($actor, $this->listId);

        /** @var list<ShoppingListItem> $items */
        $items = ShoppingListItem::query()
            ->with(['sku.product', 'sku.salesUnit'])
            ->where('shopping_list_id', $list->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        foreach ($items as $item) {
            $this->quantities[$item->id] ??= $item->quantity->format();
        }

        $owns = $lists->owns($actor, $list);

        return view('livewire.portal.ordering.shopping-list-show', [
            'list' => $list,
            'items' => $items,
            'owns' => $owns,
            'ownerName' => $owns ? null : ($lists->ownerNames([$list])[$list->membership_id] ?? null),
        ])->title($list->name);
    }

    private function item(ShoppingLists $lists, string $itemId): ShoppingListItem
    {
        $list = $lists->find($lists->actor(), $this->listId);

        return ShoppingListItem::query()
            ->with(['sku.salesUnit'])
            ->where('shopping_list_id', $list->id)
            ->whereKey(UntrustedId::uuid($itemId, ShoppingListItem::class))
            ->firstOrFail();
    }

    private function clearFeedback(): void
    {
        $this->resetErrorBag();
        $this->message = null;
        $this->failure = null;
        $this->report = null;
    }
}
