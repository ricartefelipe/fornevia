<?php

declare(strict_types=1);

use App\Support\Navigation\Menu;

it('marca o módulo inteiro da equipe e a seção do portal como ativos', function (string $route, string $pattern): void {
    expect(Menu::activePattern($route))->toBe($pattern);
})->with([
    'estoque e subtelas' => ['inventory.index', 'inventory.*'],
    'catálogo e subtelas' => ['catalog.products', 'catalog.*'],
    'preços' => ['pricing.lists', 'pricing.*'],
    'painel' => ['dashboard', 'dashboard*'],
    'carrinho do portal' => ['portal.cart', 'portal.cart*'],
    'pedidos do portal' => ['portal.orders', 'portal.orders*'],
]);
