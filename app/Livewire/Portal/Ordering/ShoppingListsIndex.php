<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Ordering;

use App\Actions\Ordering\Portal\AddListToCart;
use App\Actions\Ordering\Portal\DeleteShoppingList;
use App\Actions\Ordering\Portal\SaveShoppingList;
use App\Actions\Ordering\Portal\ShoppingLists;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Domain\Ordering\Exceptions\InvalidShoppingList;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Listas de compra do comprador (próprias e compartilhadas da empresa):
 * criação, exclusão das próprias e inclusão de uma lista no carrinho.
 */
#[Layout('layouts.app')]
#[Title('Listas de compra')]
final class ShoppingListsIndex extends Component
{
    public string $name = '';

    public bool $shared = false;

    #[Locked]
    public ?string $message = null;

    #[Locked]
    public ?string $failure = null;

    /** @var array{summary: string, added: int, rejected: int, lines: list<array<string, mixed>>}|null */
    #[Locked]
    public ?array $report = null;

    #[Locked]
    public ?string $reportListName = null;

    public function create(SaveShoppingList $save): void
    {
        $this->clearFeedback();

        try {
            $list = $save->handle(null, $this->name, $this->shared);
        } catch (InvalidShoppingList $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        session()->flash('success', "Lista {$list->name} criada. Inclua os itens pelo código do SKU.");
        $this->redirectRoute('portal.lists.show', ['list' => $list->id]);
    }

    public function delete(string $listId, DeleteShoppingList $delete): void
    {
        $this->clearFeedback();

        try {
            $name = $delete->handle($listId);
        } catch (InvalidShoppingList $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->message = "Lista {$name} excluída.";
    }

    public function addToCart(string $listId, AddListToCart $add, ShoppingLists $lists): void
    {
        $this->clearFeedback();
        $list = $lists->find($lists->actor(), $listId);

        try {
            $report = $add->handle($list->id);
        } catch (InvalidCartItem $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->report = $report->toDisplay();
        $this->reportListName = $list->name;
    }

    public function render(ShoppingLists $lists): View
    {
        $actor = $lists->actor();
        $items = $lists->index($actor);

        return view('livewire.portal.ordering.shopping-lists-index', [
            'lists' => $items,
            'owners' => $lists->ownerNames($items),
            'membershipId' => $actor->membership->id,
        ]);
    }

    private function clearFeedback(): void
    {
        $this->resetErrorBag();
        $this->message = null;
        $this->failure = null;
        $this->report = null;
        $this->reportListName = null;
    }
}
