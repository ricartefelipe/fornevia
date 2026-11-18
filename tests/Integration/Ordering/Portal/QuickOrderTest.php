<?php

declare(strict_types=1);

use App\Actions\Ordering\Portal\QuickOrder;
use App\Data\Ordering\Portal\CartAdditionLine;
use App\Data\Ordering\Portal\CartAdditionReport;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Identity\SystemRole;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Integration\Ordering\Portal\PortalOrderingFixtures as Fixtures;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Pedido rápido por SKU (RF-060): linhas "CÓDIGO quantidade" com
| separadores comuns, conferidas uma a uma; relatório por linha.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '5', unitPriceCents: 1_000);
    $this->buyer = Fixtures::buyer($this->scenario);
    $this->screw = Fixtures::sku($this->scenario, 'PAR-CX12', priceCents: 300, options: ['minimum' => '12', 'multiple' => '12'], name: 'Parafuso sextavado');
    $this->sand = Fixtures::sku($this->scenario, 'ARE-M', priceCents: 9_000, options: ['unit' => 'M', 'minimum' => '0,5', 'multiple' => '0,5'], name: 'Areia média');

    $this->quick = fn (string $text, ?array $buyer = null): CartAdditionReport => Fixtures::as(
        $this->scenario,
        ($buyer ?? $this->buyer)['membership'],
        static fn (): CartAdditionReport => app(QuickOrder::class)->handle($text),
    );
});

it('aceita separadores comuns, código em minúsculas, unidade ao final e soma ao carrinho', function (): void {
    $code = $this->scenario->sku->code;
    $text = implode("\n", [
        "{$code} 2",
        'par-cx12;24',
        '',
        "ARE-M\t1,5",
        '"ARE-M","2,5"',
        'PAR-CX12 | 12 UN',
        "{$code}: 1",
    ]);

    $report = ($this->quick)($text);

    expect(array_map(static fn (CartAdditionLine $line): array => [$line->lineNumber, $line->status, $line->quantity?->format()], $report->lines))->toBe([
        [1, 'added', '2'],
        [2, 'added', '24'],
        [4, 'added', '1,5'],
        [5, 'added', '2,5'],
        [6, 'added', '12'],
        [7, 'added', '1'],
    ])->and($report->addedCount())->toBe(6)
        ->and($report->rejectedCount())->toBe(0)
        ->and(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([
            'ARE-M' => '4',
            $code => '3',
            'PAR-CX12' => '36',
        ]);
});

it('informa cada linha recusada e grava apenas as válidas', function (): void {
    $hidden = Fixtures::sku($this->scenario, 'RES-01', options: ['visibility' => ProductVisibility::Restricted]);
    $unpriced = Fixtures::sku($this->scenario, 'SEM-PRECO', priceCents: null);
    Catalog::sku($other = TenantScenario::create(), Catalog::product($other, 'Item de outro distribuidor'), 'EXT-01');

    $report = ($this->quick)(implode("\n", [
        'PAR-CX12 13',
        'ARE-M 1,25',
        'XYZ-999 3',
        'RES-01 1',
        'EXT-01 1',
        'SEM-PRECO 1',
        'PAR-CX12',
        '@@@ 10',
        'PAR-CX12 dez',
        'PAR-CX12 24 CX',
        'ARE-M 2',
    ]));

    $byLine = [];

    foreach ($report->lines as $line) {
        $byLine[$line->lineNumber] = [$line->status, implode(' ', $line->messages), $line->suggestion?->format()];
    }

    expect($byLine)->toMatchArray([
        1 => ['rejected', 'Este item é vendido em múltiplos de 12 UN.', '24'],
        2 => ['rejected', 'Este item é vendido em múltiplos de 0,5 M.', '1,5'],
        3 => ['rejected', 'Código XYZ-999 não encontrado entre os produtos disponíveis para a sua empresa.', null],
        4 => ['rejected', 'Código RES-01 não encontrado entre os produtos disponíveis para a sua empresa.', null],
        5 => ['rejected', 'Código EXT-01 não encontrado entre os produtos disponíveis para a sua empresa.', null],
        7 => ['rejected', 'Informe a quantidade depois do código.', null],
        8 => ['rejected', 'Linha fora do formato "CÓDIGO quantidade".', null],
        9 => ['rejected', 'Informe a quantidade em números, por exemplo 12 ou 2,5.', null],
        10 => ['rejected', 'O item PAR-CX12 é vendido em UN, não em CX.', null],
        11 => ['added', '', null],
    ])->and($byLine[6][0])->toBe('rejected')
        ->and($byLine[6][1])->toContain('sem preço vigente')
        ->and($report->summary())->toBe('1 item adicionado ao carrinho; 10 não adicionados.')
        ->and(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe(['ARE-M' => '2']);

    expect($hidden->code)->toBe('RES-01')->and($unpriced->code)->toBe('SEM-PRECO');
});

it('avisa sobre estoque insuficiente sem impedir a inclusão', function (): void {
    $report = ($this->quick)($this->scenario->sku->code.' 8');

    expect($report->lines[0]->status)->toBe('added')
        ->and($report->lines[0]->warnings)->toBe(['Estoque insuficiente no momento: ajuste a quantidade ou remova o item antes de confirmar.']);
});

it('recusa texto vazio, longo demais ou com linhas demais', function (string $text, string $message): void {
    expect(fn () => ($this->quick)($text))->toThrow(InvalidCartItem::class, $message);
})->with([
    'vazio' => ["\n  \n", 'Cole ao menos uma linha'],
    'longo demais' => [str_repeat('A', 20_001), 'excede o limite de 20.000 caracteres'],
    'linhas demais' => [str_repeat("PAR-CX12 12\n", 201), 'no máximo 200 linhas'],
]);

it('exige a permissão de carrinho', function (): void {
    $approver = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Approver);

    expect(fn () => ($this->quick)('PAR-CX12 12', $approver))->toThrow(AuthorizationException::class);
});
