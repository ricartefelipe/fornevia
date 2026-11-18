<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Livewire\Staff\Pricing\PriceListEdit;
use App\Livewire\Staff\Pricing\PriceListsIndex;
use App\Models\Audit\AuditLog;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Models\Pricing\PriceTier;
use App\Models\Pricing\PricingPolicy;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->viewer = $this->tenant->addStaff(SystemRole::Viewer);

    $this->lists = fn (): array => $this->tenant->system(static fn (): array => PriceList::query()->orderBy('code')
        ->get()
        ->mapWithKeys(static fn (PriceList $l): array => [$l->code => [$l->scope->value, $l->status->value, $l->priority]])
        ->all());

    $this->items = fn (PriceList $list): array => $this->tenant->system(static fn (): array => PriceListItem::query()
        ->with('tiers')
        ->where('price_list_id', $list->id)
        ->get()
        ->mapWithKeys(static fn (PriceListItem $i): array => [$i->sku_id => [
            $i->unit_price_cents->cents,
            $i->tiers->map(static fn (PriceTier $t): array => [$t->min_quantity->toDatabase(), $t->unit_price_cents->cents])->all(),
        ]])
        ->all());
});

describe('lista de tabelas', function (): void {
    it('cria tabela em rascunho e redireciona para a edição', function (): void {
        $group = Fx::group($this->tenant, 'VAREJO', 'Varejo alimentar');

        Fx::asMember($this->tenant, $this->manager, function () use ($group): void {
            $component = Livewire::test(PriceListsIndex::class)
                ->assertSet('validFrom', '2026-11-17')
                ->set('code', 'varejo-2027')
                ->set('name', 'Varejo 2027')
                ->set('scope', 'group')
                ->set('customerGroupId', $group->id)
                ->set('priority', '3')
                ->set('validFrom', '2027-01-01')
                ->set('validUntil', '2027-12-31')
                ->call('create')
                ->assertHasNoErrors();

            $list = PriceList::query()->where('code', 'VAREJO-2027')->sole();

            $component->assertRedirect(route('pricing.lists.edit', ['priceList' => $list->id]));

            expect(session('success'))->toBe('Tabela VAREJO-2027 criada como rascunho. Cadastre os itens e ative a tabela.');
        });

        expect(($this->lists)())->toBe(['VAREJO-2027' => ['group', 'draft', 3]])
            ->and(Fx::auditActions($this->tenant, 'price_list.'))->toBe(['price_list.created']);
    });

    it('valida os campos do cabeçalho antes de chamar a Action', function (array $fields, array $errors): void {
        Fx::asMember($this->tenant, $this->manager, static function () use ($fields, $errors): void {
            $component = Livewire::test(PriceListsIndex::class);

            foreach ($fields as $name => $value) {
                $component->set($name, $value);
            }

            $component->call('create')->assertHasErrors($errors)->assertNoRedirect();
        });

        expect(($this->lists)())->toBe([]);
    })->with([
        'obrigatórios' => [['code' => '', 'name' => ''], ['code' => 'required', 'name' => 'required']],
        'código com espaço' => [['code' => 'TABELA 1', 'name' => 'Tabela'], ['code' => 'regex']],
        'grupo obrigatório' => [['code' => 'G1', 'name' => 'Grupo', 'scope' => 'group'], ['customerGroupId' => 'required_if']],
        'empresa obrigatória' => [['code' => 'C1', 'name' => 'Empresa', 'scope' => 'customer'], ['buyerCompanyId' => 'required_if']],
        'escopo inexistente' => [['code' => 'X1', 'name' => 'X', 'scope' => 'global'], ['scope']],
        'término antes do início' => [['code' => 'X2', 'name' => 'X', 'validFrom' => '2027-02-01', 'validUntil' => '2027-01-31'], ['validUntil' => 'after_or_equal']],
        'prioridade não inteira' => [['code' => 'X3', 'name' => 'X', 'priority' => '2,5'], ['priority' => 'integer']],
    ]);

    it('mostra a violação de regra no campo correspondente', function (): void {
        Fx::priceList($this->tenant, 'PADRAO');
        $other = TenantScenario::create();
        $foreignGroup = Fx::group($other, 'VAREJO', 'Varejo alimentar');

        Fx::asMember($this->tenant, $this->manager, static function () use ($foreignGroup): void {
            Livewire::test(PriceListsIndex::class)
                ->set('code', 'padrao')
                ->set('name', 'Duplicada')
                ->call('create')
                ->assertHasErrors(['code' => 'Já existe uma tabela de preço com o código PADRAO.'])
                ->set('code', 'VAREJO')
                ->set('scope', 'group')
                ->set('customerGroupId', $foreignGroup->id)
                ->call('create')
                ->assertHasErrors(['customerGroupId' => 'Selecione um grupo de clientes cadastrado.'])
                ->assertNoRedirect();
        });

        expect(array_keys(($this->lists)()))->toBe(['PADRAO']);
    });

    it('recusa a criação para perfil apenas de consulta, mesmo chamando a ação diretamente', function (): void {
        Fx::asMember($this->tenant, $this->viewer, static fn () => Livewire::test(PriceListsIndex::class)
            ->assertDontSee('Criar tabela')
            ->set('code', 'INDEVIDA')
            ->set('name', 'Tabela indevida')
            ->call('create')
            ->assertForbidden());

        expect(($this->lists)())->toBe([]);
    });

    it('filtra por escopo, situação e busca', function (): void {
        $group = Fx::group($this->tenant, 'VAREJO', 'Varejo alimentar');
        Fx::priceList($this->tenant, 'PADRAO');
        Fx::priceList($this->tenant, 'RASCUNHO-27', activate: false);
        Fx::priceList($this->tenant, 'VAREJO-26', PriceListScope::Group, $group->id);

        Fx::asMember($this->tenant, $this->viewer, static fn () => Livewire::test(PriceListsIndex::class)
            ->assertSee(['PADRAO', 'RASCUNHO-27', 'VAREJO-26'])
            ->set('scopeFilter', 'group')
            ->assertSee('VAREJO-26')
            ->assertDontSee('RASCUNHO-27')
            ->set('scopeFilter', '')
            ->set('statusFilter', 'draft')
            ->assertSee('RASCUNHO-27')
            ->assertDontSee('VAREJO-26')
            ->set('statusFilter', '')
            ->set('search', 'varejo')
            ->assertSee('VAREJO-26')
            ->assertDontSee('RASCUNHO-27')
            ->set('search', 'inexistente')
            ->assertSee('Nenhuma tabela encontrada')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSee('RASCUNHO-27'));
    });

    it('atualiza a margem mínima padrão e valida o percentual', function (): void {
        Fx::asMember($this->tenant, $this->manager, static fn () => Livewire::test(PriceListsIndex::class)
            ->assertSet('minMargin', '10')
            ->set('minMargin', '150')
            ->call('saveSettings')
            ->assertHasErrors(['minMargin'])
            ->set('minMargin', '12,5')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSet('message', 'Margem mínima padrão atualizada para 12,50%.'));

        expect($this->tenant->system(static fn (): int => (int) PricingPolicy::query()->value('min_margin_bps')))->toBe(1_250);
    });
});

describe('edição de tabela', function (): void {
    beforeEach(function (): void {
        $this->list = Fx::priceList($this->tenant, 'PADRAO', activate: false);
        $this->sku = Fx::sku($this->tenant, 'SAB-90', 'Sabonete em barra 90 g');
    });

    it('busca o SKU, define preço e faixas e lista o item', function (): void {
        Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->set('skuSearch', 'sabonete')
            ->assertSee('SAB-90')
            ->call('selectSku', $this->sku->id)
            ->assertSet('selectedSkuId', $this->sku->id)
            ->set('unitPrice', '1,89')
            ->call('addTier')
            ->call('addTier')
            ->set('tiers.0.min', '12')
            ->set('tiers.0.price', '1,79')
            ->set('tiers.1.min', '144')
            ->set('tiers.1.price', '1,59')
            ->call('saveItem')
            ->assertHasNoErrors()
            ->assertSet('message', 'Preço do SKU SAB-90 salvo.')
            ->assertSet('selectedSkuId', null)
            ->assertSee('R$ 1,89')
            ->assertSee('A partir de 144 UN: R$ 1,59'));

        expect(($this->items)($this->list))->toBe([$this->sku->id => [189, [['12.000', 179], ['144.000', 159]]]]);
    });

    it('carrega o item existente para edição e substitui as faixas', function (): void {
        $item = Fx::item($this->tenant, $this->list, $this->sku, '1,89', [['12', '1,79']]);

        Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->call('editItem', $item->id)
            ->assertSet('unitPrice', '1,89')
            ->assertSet('tiers', [['min' => '12', 'price' => '1,79']])
            ->call('removeTier', 0)
            ->set('unitPrice', '1,99')
            ->call('saveItem')
            ->assertHasNoErrors());

        expect(($this->items)($this->list))->toBe([$this->sku->id => [199, []]]);
    });

    it('associa erros de faixa e de valor ao campo digitado', function (): void {
        Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->call('saveItem')
            ->assertHasErrors(['skuSearch'])
            ->call('selectSku', $this->sku->id)
            ->set('unitPrice', '1,8x')
            ->call('saveItem')
            ->assertHasErrors(['unitPrice' => 'Informe um valor em reais, por exemplo 1.234,56.'])
            ->set('unitPrice', '1,89')
            ->call('addTier')
            ->call('addTier')
            ->set('tiers.0.min', '24')
            ->set('tiers.0.price', '1,79')
            ->set('tiers.1.min', '12')
            ->set('tiers.1.price', '1,69')
            ->call('saveItem')
            ->assertHasErrors(['tiers.1.min' => 'A quantidade mínima da faixa 2 deve ser maior que a da faixa anterior.'])
            ->set('tiers.1.min', '48')
            ->set('tiers.1.price', '0')
            ->call('saveItem')
            ->assertHasErrors(['tiers.1.price' => 'O preço da faixa 2 deve ser maior que zero.'])
            ->set('tiers.1.min', '')
            ->call('saveItem')
            ->assertHasErrors(['tiers.1.min' => 'required']));

        expect(($this->items)($this->list))->toBe([]);
    });

    it('altera o cabeçalho, ativa e arquiva a tabela', function (): void {
        Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->assertSet('code', 'PADRAO')
            ->set('name', 'Tabela padrão 2026')
            ->set('priority', '2')
            ->call('saveHeader')
            ->assertHasNoErrors()
            ->assertSet('message', 'Cabeçalho da tabela atualizado.')
            ->call('activate')
            ->assertSet('message', 'Tabela ativada: os preços passam a valer na vigência informada.')
            ->set('archiveReason', 'Substituída pela tabela de 2027')
            ->call('archive')
            ->assertSet('message', 'Tabela arquivada: deixou de participar do cálculo de preços.')
            ->assertSee('Tabela arquivada: somente consulta.')
            ->assertDontSee('Salvar cabeçalho'));

        $stored = $this->tenant->system(fn (): PriceList => PriceList::query()->findOrFail($this->list->id));
        $archived = $this->tenant->system(static fn (): AuditLog => AuditLog::query()->where('action', 'price_list.archived')->sole());

        expect($stored->name)->toBe('Tabela padrão 2026')
            ->and($stored->priority)->toBe(2)
            ->and($stored->status)->toBe(PriceListStatus::Archived)
            ->and($archived->reason)->toBe('Substituída pela tabela de 2027');
    });

    it('mostra a recusa de ativação de tabela com vigência encerrada', function (): void {
        $expired = Fx::priceList($this->tenant, 'OUTUBRO', from: '2026-10-01', until: '2026-10-31', activate: false);

        Fx::asMember($this->tenant, $this->manager, static fn () => Livewire::test(PriceListEdit::class, ['priceList' => $expired])
            ->call('activate')
            ->assertHasErrors(['status'])
            ->assertSee('A vigência desta tabela já terminou.'));
    });

    it('remove o item da tabela', function (): void {
        $item = Fx::item($this->tenant, $this->list, $this->sku, '1,89');

        Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->assertSee('SAB-90')
            ->call('removeItem', $item->id)
            ->assertSet('message', 'Item removido da tabela.')
            ->assertSee('Nenhum item'));

        expect(($this->items)($this->list))->toBe([]);
    });

    it('trata identificadores de outro tenant ou malformados como inexistentes', function (string $method): void {
        $other = TenantScenario::create();
        $foreignList = Fx::priceList($other, 'PADRAO');
        $foreignSku = Fx::sku($other, 'SAB-90', 'Sabonete em barra 90 g');
        $foreignItem = Fx::item($other, $foreignList, $foreignSku, '1,49');

        $target = match ($method) {
            'selectSku' => $foreignSku->id,
            default => $foreignItem->id,
        };

        Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->call($method, $target)
            ->assertNotFound());

        Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->call($method, 'SAB-90')
            ->assertNotFound());

        expect(($this->items)($this->list))->toBe([])
            ->and($other->system(static fn (): int => PriceListItem::query()->count()))->toBe(1);
    })->with(['selectSku', 'editItem', 'removeItem']);

    it('não aceita item de outra tabela do mesmo tenant', function (): void {
        $otherList = Fx::priceList($this->tenant, 'PROMO');
        $foreignItem = Fx::item($this->tenant, $otherList, $this->sku, '1,49');

        Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->call('removeItem', $foreignItem->id)
            ->assertNotFound());

        expect(($this->items)($otherList))->toHaveCount(1);
    });

    it('protege a tabela e o SKU selecionado contra alteração pelo cliente', function (): void {
        $otherList = Fx::priceList($this->tenant, 'PROMO');

        Fx::asMember($this->tenant, $this->manager, function () use ($otherList): void {
            $component = Livewire::test(PriceListEdit::class, ['priceList' => $this->list]);

            expect(fn () => $component->set('priceListId', $otherList->id))->toThrow(CannotUpdateLockedPropertyException::class)
                ->and(fn () => $component->set('selectedSkuId', $this->sku->id))->toThrow(CannotUpdateLockedPropertyException::class);
        });
    });

    it('refaz a autorização nas ações de perfil apenas de consulta', function (): void {
        $item = Fx::item($this->tenant, $this->list, $this->sku, '1,89');

        Fx::asMember($this->tenant, $this->viewer, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->assertSee('Seu perfil permite consultar a tabela.')
            ->call('activate')
            ->assertForbidden());

        Fx::asMember($this->tenant, $this->viewer, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->call('removeItem', $item->id)
            ->assertForbidden());

        Fx::asMember($this->tenant, $this->viewer, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $this->list])
            ->call('selectSku', $this->sku->id)
            ->set('unitPrice', '0,99')
            ->call('saveItem')
            ->assertForbidden());

        expect(($this->items)($this->list))->toBe([$this->sku->id => [189, []]])
            ->and($this->tenant->system(fn (): PriceListStatus => PriceList::query()->findOrFail($this->list->id)->status))->toBe(PriceListStatus::Draft);
    });
});
