<?php

declare(strict_types=1);

use App\Actions\Ordering\Portal\AddListToCart;
use App\Actions\Ordering\Portal\DeleteShoppingList;
use App\Actions\Ordering\Portal\RemoveShoppingListItem;
use App\Actions\Ordering\Portal\SaveShoppingList;
use App\Actions\Ordering\Portal\SaveShoppingListItem;
use App\Actions\Ordering\Portal\ShoppingLists;
use App\Data\Ordering\Portal\CartAdditionLine;
use App\Data\Ordering\Portal\CartAdditionReport;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Domain\Ordering\Exceptions\InvalidShoppingList;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Ordering\ShoppingList;
use App\Models\Ordering\ShoppingListItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Ordering\Portal\PortalOrderingFixtures as Fixtures;
use Tests\Support\Builders\OrderingScenario;

/*
| Listas de compra (RF-060): criar, renomear, compartilhar, excluir,
| incluir e remover itens, e enviar a lista ao carrinho com preço e
| disponibilidade vigentes. Somente quem criou a lista a altera.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '100', unitPriceCents: 1_000);
    $this->buyer = Fixtures::buyer($this->scenario);
    $this->colleague = Fixtures::buyer($this->scenario, $this->buyer['company']);
    $this->screw = Fixtures::sku($this->scenario, 'PAR-CX12', priceCents: 300, options: ['minimum' => '12', 'multiple' => '12'], name: 'Parafuso sextavado');

    $this->as = fn (Membership $membership, Closure $callback): mixed => Fixtures::as($this->scenario, $membership, $callback);
    $this->save = fn (?string $id, string $name, bool $shared = false, ?Membership $who = null): ShoppingList => ($this->as)(
        $who ?? $this->buyer['membership'],
        static fn (): ShoppingList => app(SaveShoppingList::class)->handle($id, $name, $shared),
    );
    $this->item = fn (string $listId, string $code, string $quantity, ?Membership $who = null): ShoppingListItem => ($this->as)(
        $who ?? $this->buyer['membership'],
        static fn (): ShoppingListItem => app(SaveShoppingListItem::class)->handle($listId, $code, $quantity),
    );
    $this->toCart = fn (string $listId, ?Membership $who = null): CartAdditionReport => ($this->as)(
        $who ?? $this->buyer['membership'],
        static fn (): CartAdditionReport => app(AddListToCart::class)->handle($listId),
    );
    $this->visibleTo = fn (Membership $who): array => ($this->as)($who, static function (): array {
        $lists = app(ShoppingLists::class);

        return $lists->index($lists->actor())->pluck('name')->all();
    });
});

it('cria, renomeia, compartilha e exclui a lista do próprio comprador', function (): void {
    $list = ($this->save)(null, '  Reposição   mensal ');

    expect($list->name)->toBe('Reposição mensal')
        ->and($list->is_shared)->toBeFalse();

    $renamed = ($this->save)($list->id, 'Reposição da obra', true);

    expect($renamed->name)->toBe('Reposição da obra')
        ->and($renamed->is_shared)->toBeTrue()
        ->and(($this->visibleTo)($this->colleague['membership']))->toBe(['Reposição da obra']);

    ($this->as)($this->buyer['membership'], static fn (): string => app(DeleteShoppingList::class)->handle($list->id));

    expect($this->scenario->system(static fn (): int => ShoppingList::query()->count()))->toBe(0);
});

it('valida o nome da lista', function (string $name, string $message): void {
    expect(fn () => ($this->save)(null, $name))->toThrow(InvalidShoppingList::class, $message);
})->with([
    'vazio' => ['   ', 'Informe o nome da lista.'],
    'longo' => [str_repeat('x', 121), 'até 120 caracteres'],
]);

it('inclui itens pelo código, atualiza a quantidade do mesmo SKU e remove', function (): void {
    $list = ($this->save)(null, 'Obra norte');

    ($this->item)($list->id, 'par-cx12', '24');
    $item = ($this->item)($list->id, 'PAR-CX12', '1.200');
    ($this->item)($list->id, $this->scenario->sku->code, '3');

    expect($item->quantity->toDatabase())->toBe('1200.000')
        ->and($this->scenario->system(static fn (): int => ShoppingListItem::query()->count()))->toBe(2)
        ->and(fn () => ($this->item)($list->id, 'PAR-CX12', '13'))->toThrow(InvalidCartItem::class, 'Quantidade válida mais próxima: 24 UN.')
        ->and(fn () => ($this->item)($list->id, 'NAO-EXISTE', '1'))->toThrow(InvalidCartItem::class, 'Código NAO-EXISTE não encontrado')
        ->and(fn () => ($this->item)($list->id, '', '1'))->toThrow(InvalidCartItem::class, 'Informe o código do SKU.');

    ($this->as)($this->buyer['membership'], static fn () => app(RemoveShoppingListItem::class)->handle($list->id, $item->id));

    expect($this->scenario->system(static fn (): int => ShoppingListItem::query()->count()))->toBe(1);
});

it('deixa a lista compartilhada somente para leitura de quem não a criou', function (): void {
    $list = ($this->save)(null, 'Compartilhada', true);
    $item = ($this->item)($list->id, 'PAR-CX12', '12');
    $colleague = $this->colleague['membership'];

    expect(fn () => ($this->save)($list->id, 'Tomada', false, $colleague))->toThrow(InvalidShoppingList::class, 'Somente quem criou a lista pode alterá-la.')
        ->and(fn () => ($this->item)($list->id, 'PAR-CX12', '24', $colleague))->toThrow(InvalidShoppingList::class)
        ->and(fn () => ($this->as)($colleague, static fn () => app(RemoveShoppingListItem::class)->handle($list->id, $item->id)))->toThrow(InvalidShoppingList::class)
        ->and(fn () => ($this->as)($colleague, static fn () => app(DeleteShoppingList::class)->handle($list->id)))->toThrow(InvalidShoppingList::class);

    $report = ($this->toCart)($list->id, $colleague);

    expect($report->addedCount())->toBe(1)
        ->and(Fixtures::cartLines($this->scenario, $colleague))->toBe(['PAR-CX12' => '12'])
        ->and(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([]);
});

it('não mostra lista pessoal de colega, lista de outra empresa nem identificador adulterado', function (string $case): void {
    $listId = match ($case) {
        'pessoal de colega' => ($this->save)(null, 'Pessoal')->id,
        'outra empresa' => ($this->save)(null, 'De outra empresa', true, Fixtures::buyer($this->scenario)['membership'])->id,
        'inexistente' => (string) Str::uuid7(),
        'malformado' => 'lista-1',
    };
    $colleague = $this->colleague['membership'];

    expect(fn () => ($this->toCart)($listId, $colleague))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->save)($listId, 'Outro nome', false, $colleague))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->as)($colleague, static fn () => app(DeleteShoppingList::class)->handle($listId)))->toThrow(ModelNotFoundException::class)
        ->and(($this->visibleTo)($colleague))->toBe([]);
})->with(['pessoal de colega', 'outra empresa', 'inexistente', 'malformado']);

it('envia a lista ao carrinho ajustando quantidades à regra atual e informando itens indisponíveis', function (): void {
    $list = ($this->save)(null, 'Recorrente');
    $gone = Fixtures::sku($this->scenario, 'FORA-LINHA', name: 'Luva de raspa');
    ($this->item)($list->id, 'PAR-CX12', '24');
    ($this->item)($list->id, 'FORA-LINHA', '2');
    ($this->item)($list->id, $this->scenario->sku->code, '150');

    // Depois de montada a lista: o SKU passa a ser vendido em caixas de 20 e outro sai de linha.
    $this->scenario->system(function () use ($gone): void {
        DB::table('skus')->where('id', $this->screw->id)->update(['min_order_quantity' => '20', 'order_multiple' => '20']);
        DB::table('skus')->where('id', $gone->id)->update(['status' => 'inactive']);
    });

    $report = ($this->toCart)($list->id);
    $lines = array_map(static fn (CartAdditionLine $line): array => [$line->status, $line->skuCode, $line->quantity?->format(), $line->messages, $line->warnings], $report->lines);

    expect($lines)->toBe([
        ['adjusted', 'PAR-CX12', '40', ['Quantidade ajustada de 24 para 40 UN pela regra de compra atual.'], []],
        ['rejected', null, null, ['O item não está mais disponível para a sua empresa.'], []],
        ['added', $this->scenario->sku->code, '150', [], ['Estoque insuficiente no momento: ajuste a quantidade ou remova o item antes de confirmar.']],
    ])->and(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([
        $this->scenario->sku->code => '150',
        'PAR-CX12' => '40',
    ]);
});

it('recusa enviar lista vazia ao carrinho', function (): void {
    $list = ($this->save)(null, 'Vazia');

    expect(fn () => ($this->toCart)($list->id))->toThrow(InvalidCartItem::class, 'A lista não tem itens');
});

it('exige a permissão de listas', function (): void {
    $approver = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Approver)['membership'];
    $list = ($this->save)(null, 'Compartilhada', true);

    expect(fn () => ($this->save)(null, 'Do aprovador', false, $approver))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->toCart)($list->id, $approver))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->visibleTo)($approver))->toThrow(AuthorizationException::class);
});
