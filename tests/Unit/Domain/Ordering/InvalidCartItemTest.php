<?php

declare(strict_types=1);

use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Domain\Shared\Quantity;

/*
| Item de carrinho inválido: mensagens, sugestão de quantidade e campo.
*/

it('monta a mensagem com a sugestão e guarda os detalhes', function (): void {
    $e = InvalidCartItem::quantity('DJ-32A', ['Abaixo do mínimo.', 'Fora do múltiplo.'], Quantity::of('12'), 'CX', 'items.0.quantity');

    expect($e->getMessage())->toBe('Abaixo do mínimo. Fora do múltiplo. Quantidade válida mais próxima: 12 CX.')
        ->and($e->errorCode())->toBe('invalid_cart_item')
        ->and($e->field())->toBe('items.0.quantity')
        ->and($e->messages())->toBe(['Abaixo do mínimo.', 'Fora do múltiplo.'])
        ->and($e->suggestion()?->equals(Quantity::of('12')))->toBeTrue()
        ->and($e->details())->toBe(['field' => 'items.0.quantity', 'sku_code' => 'DJ-32A', 'suggestion' => Quantity::of('12')->format(), 'messages' => ['Abaixo do mínimo.', 'Fora do múltiplo.']]);
});

it('sem sugestão, usa só as mensagens e o campo padrão', function (): void {
    $e = InvalidCartItem::quantity('DJ-32A', ['Quantidade inválida. '], null, 'UN');

    expect($e->getMessage())->toBe('Quantidade inválida.')
        ->and($e->suggestion())->toBeNull()
        ->and($e->field())->toBe('quantity')
        ->and($e->details()['suggestion'])->toBeNull();
});

it('motivo simples vira a única mensagem', function (): void {
    $e = InvalidCartItem::because('SKU inativo.', 'DJ-40A', 'sku');

    expect($e->messages())->toBe(['SKU inativo.'])
        ->and($e->field())->toBe('sku')
        ->and($e->details())->toBe(['field' => 'sku', 'sku_code' => 'DJ-40A'])
        ->and(InvalidCartItem::because('Sem SKU.')->details())->toBe(['field' => 'quantity', 'sku_code' => null]);
});
