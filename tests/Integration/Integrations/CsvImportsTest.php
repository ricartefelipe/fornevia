<?php

declare(strict_types=1);

use App\Actions\Integrations\CsvImports;
use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Enums\Identity\SystemRole;
use App\Jobs\ApplyCsvImport;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Integrations\CsvImport;
use App\Models\Integrations\CsvImportRow;
use App\Models\Inventory\StockLevel;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\Builders\OrderingScenario;

/*
| Importação CSV (RF-113, ADR-029): prévia com o resultado de cada linha,
| limites, duplicidade no arquivo e no cadastro, as duas políticas de
| aplicação e reprocessamento sem repetir o que já foi aplicado.
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync', 'integrations.imports.max_rows' => 5000, 'integrations.imports.max_bytes' => 2 * 1024 * 1024]);
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->owner = $this->scenario->tenant->ownerMembership;
    $this->imports = fn (Closure $call, $membership = null) => $this->scenario->tenant->actingAs($membership ?? $this->owner, fn () => $call(app(CsvImports::class)));
    $this->preview = fn (string $kind, string $csv, string $policy = 'valid_only') => ($this->imports)(fn (CsvImports $i) => $i->preview($kind, $policy, 'arquivo.csv', $csv));
    $this->confirm = fn (CsvImport $import) => ($this->imports)(fn (CsvImports $i) => $i->confirm($import->id));
    $this->fresh = fn (CsvImport $import) => $this->scenario->system(fn () => [CsvImport::query()->findOrFail($import->id), CsvImportRow::query()->where('csv_import_id', $import->id)->orderBy('line')->get()]);
    $this->companies = fn (): array => $this->scenario->system(fn () => BuyerCompany::query()->pluck('legal_name')->sort()->values()->all());
});

function customersCsv(array $rows): string
{
    $lines = ['razao_social;nome_fantasia;cnpj;email;telefone'];

    foreach ($rows as $row) {
        $lines[] = implode(';', $row);
    }

    return implode("\n", $lines)."\n";
}

it('mostra o resultado de cada linha na prévia, sem gravar cadastro', function (): void {
    $existing = $this->scenario->system(fn () => BuyerCompany::query()->value('document'));
    $repeated = BrazilianDocuments::cnpj();
    $before = ($this->companies)();

    $import = ($this->preview)('customers', customersCsv([
        ['Alfa Indústria Ltda', 'Alfa', BrazilianDocuments::cnpj(), 'compras@alfa.com.br', '1133334444'],
        ['Beta Comércio Ltda', 'Beta', $repeated, '', ''],
        ['Gama Comércio Ltda', 'Gama', $repeated, '', ''],
        ['Delta Ltda', 'Delta', '11.111.111/1111-11', 'sem-arroba', ''],
        ['', 'Sem razão', BrazilianDocuments::cnpj(), '', ''],
    ]).($existing !== null ? "Já Cadastrada Ltda;Já;{$existing};;\n" : ''));

    [$fresh, $rows] = ($this->fresh)($import);
    $errors = $rows->mapWithKeys(fn (CsvImportRow $r): array => [$r->line => $r->errors])->all();

    expect($fresh->status)->toBe('validated')
        ->and($fresh->rows_total)->toBe($existing !== null ? 6 : 5)
        ->and($fresh->rows_valid)->toBe(2)
        ->and($errors[2])->toBe([])->and($errors[3])->toBe([])
        ->and($errors[4])->toBe(['CNPJ repetido no arquivo (linha 3).'])
        ->and($errors[5])->toBe(['CNPJ inválido.', 'E-mail inválido.'])
        ->and($errors[6])->toBe(['Razão social obrigatória (até 200 caracteres).'])
        ->and(($this->companies)())->toBe($before);

    if ($existing !== null) {
        expect($errors[7])->toBe(['CNPJ já cadastrado.']);
    }
});

it('recusa arquivo fora dos limites, sem as colunas ou fora de UTF-8', function (string $csv, string $message, array $config = []): void {
    config($config);

    expect(fn () => ($this->preview)('customers', $csv))->toThrow(InvalidIntegration::class, $message);
})->with([
    'vazio' => ['', 'conteúdo'],
    'grande demais' => [customersCsv([['A', 'A', '1', '', '']]), 'MB', ['integrations.imports.max_bytes' => 10]],
    'linhas demais' => [customersCsv([['A', 'A', '1', '', ''], ['B', 'B', '2', '', '']]), 'linhas', ['integrations.imports.max_rows' => 1]],
    'coluna ausente' => ["razao_social;cnpj\nA;1\n", 'nome_fantasia'],
    'fora de UTF-8' => ["razao_social;nome_fantasia;cnpj;email;telefone\n\xE9;a;1;;\n", 'UTF-8'],
    'sem linhas' => ["razao_social;nome_fantasia;cnpj;email;telefone\n", 'linha'],
]);

it('aplica só as linhas válidas na política só as válidas', function (): void {
    $import = ($this->preview)('customers', customersCsv([
        ['Alfa Indústria Ltda', 'Alfa', BrazilianDocuments::cnpj(), '', ''],
        ['Delta Ltda', 'Delta', '123', '', ''],
        ['Ômega Distribuidora Ltda', 'Ômega', BrazilianDocuments::cnpj(), '', ''],
    ]));

    ($this->confirm)($import);
    [$fresh, $rows] = ($this->fresh)($import);

    expect($fresh->status)->toBe('done')->and($fresh->rows_applied)->toBe(2)->and($fresh->finished_at)->not->toBeNull()
        ->and($rows->pluck('status')->all())->toBe(['applied', 'invalid', 'applied'])
        ->and($rows[0]->entity_id)->not->toBeNull()
        ->and(($this->companies)())->toContain('Alfa Indústria Ltda', 'Ômega Distribuidora Ltda')->not->toContain('Delta Ltda');
});

it('recusa a importação inteira na política tudo ou nada quando há linha inválida', function (): void {
    $before = ($this->companies)();
    $import = ($this->preview)('customers', customersCsv([
        ['Alfa Indústria Ltda', 'Alfa', BrazilianDocuments::cnpj(), '', ''],
        ['Delta Ltda', 'Delta', '123', '', ''],
    ]), 'all_or_none');

    ($this->confirm)($import);
    [$fresh, $rows] = ($this->fresh)($import);

    expect($fresh->status)->toBe('rejected')->and($fresh->rows_applied)->toBe(0)
        ->and($rows->pluck('status')->all())->toBe(['valid', 'invalid'])
        ->and(($this->companies)())->toBe($before)
        ->and(fn () => ($this->confirm)($import))->toThrow(InvalidIntegration::class, 'já foi confirmada');
});

it('desfaz tudo na política tudo ou nada quando uma linha é recusada ao aplicar', function (): void {
    $import = ($this->preview)('products', implode("\n", [
        'codigo_sku;produto;unidade;fator_conversao;quantidade_minima;multiplo',
        'IMP-001;Cabo flexível 2,5 mm;UN;1;1;1',
        'IMP-002;Cabo flexível 4 mm;UN;1;1;1',
    ]), 'all_or_none');

    // Entre a prévia e a aplicação, outro cadastro usa o código da linha 3.
    $this->scenario->system(fn () => Sku::query()->whereKey($this->scenario->sku->id)->update(['code' => 'IMP-002']));
    ($this->confirm)($import);
    [$fresh, $rows] = ($this->fresh)($import);

    expect($fresh->status)->toBe('failed')->and($fresh->rows_applied)->toBe(0)
        ->and($fresh->failure)->toStartWith('Linha 3:')->toEndWith('Nada foi aplicado.')
        ->and($rows->pluck('status')->all())->toBe(['valid', 'valid'])
        ->and($this->scenario->system(fn () => Sku::query()->where('code', 'IMP-001')->exists()))->toBeFalse()
        ->and($this->scenario->system(fn () => Product::query()->where('name', 'Cabo flexível 2,5 mm')->exists()))->toBeFalse();
});

it('pula a linha recusada ao aplicar e mantém as demais na política só as válidas', function (): void {
    $import = ($this->preview)('products', implode("\n", [
        'codigo_sku;produto;unidade;fator_conversao;quantidade_minima;multiplo',
        'IMP-010;Disjuntor bipolar;UN;1;1;1',
        'IMP-011;Disjuntor tripolar;UN;1;1;1',
        'IMP-012;Disjuntor tripolar;UN;1;5;5',
    ]));

    // A linha 3 cria o produto "Disjuntor tripolar" e é desfeita; a linha 4
    // não pode reaproveitar o produto que não existe mais.
    $this->scenario->system(fn () => Sku::query()->whereKey($this->scenario->sku->id)->update(['code' => 'IMP-011']));
    ($this->confirm)($import);
    [$fresh, $rows] = ($this->fresh)($import);

    expect($fresh->status)->toBe('done')->and($fresh->rows_applied)->toBe(2)
        ->and($rows->pluck('status')->all())->toBe(['applied', 'skipped', 'applied'])
        ->and($rows[1]->errors)->toHaveCount(1)
        ->and($this->scenario->system(fn () => Sku::query()->where('code', 'IMP-012')->firstOrFail()->product->name))->toBe('Disjuntor tripolar');
});

it('agrupa SKUs do mesmo produto e reaproveita produto já cadastrado', function (): void {
    $existing = $this->scenario->sku->product;
    $import = ($this->preview)('products', implode("\r\n", [
        "\u{FEFF}Codigo SKU,Produto,Unidade,Fator conversao,Quantidade minima,Multiplo",
        'imp-020,Luminária LED,UN,1,1,1',
        'IMP-021,luminária led,UN,"1,5",2,2',
        "IMP-022,{$existing->name},UN,1,1,1",
        'IMP-020,Outra,UN,1,1,1',
        'IMP 023,Outra,XX,0,abc,1',
    ]));

    [$fresh, $rows] = ($this->fresh)($import);
    expect($fresh->rows_valid)->toBe(3)
        ->and($rows[3]->errors)->toBe(['Código de SKU repetido no arquivo (linha 2).'])
        ->and($rows[4]->errors)->toHaveCount(4);

    ($this->confirm)($import);
    $skus = $this->scenario->system(fn () => Sku::query()->whereIn('code', ['IMP-020', 'IMP-021', 'IMP-022'])->orderBy('code')->get());

    expect($skus)->toHaveCount(3)
        ->and($skus[0]->product_id)->toBe($skus[1]->product_id)
        ->and((string) $skus[1]->conversion_factor)->toStartWith('1.5')
        ->and($skus[2]->product_id)->toBe($existing->id);
});

it('lança as entradas de estoque uma única vez, mesmo reprocessando', function (): void {
    $sku = $this->scenario->sku;
    $import = ($this->preview)('stock', "deposito;codigo_sku;quantidade\nCD1;{$sku->code};5\ncd1;{$sku->code};2\nXX;{$sku->code};1\nCD1;NAO-EXISTE;0\nCD1;{$sku->code};2,5\n");
    ($this->confirm)($import);
    $onHand = fn (): string => $this->scenario->system(fn () => StockLevel::query()->where('sku_id', $sku->id)->sole()->on_hand->toDatabase());

    [$fresh, $rows] = ($this->fresh)($import);
    expect($fresh->status)->toBe('done')->and($fresh->rows_applied)->toBe(2)
        ->and($rows[2]->errors)->toBe(['Depósito não cadastrado ou inativo.'])
        ->and($rows[3]->errors)->toBe(['SKU não cadastrado.', 'Quantidade deve ser positiva.'])
        ->and($rows[4]->errors)->toBe(['A unidade base UN não aceita casas decimais.'])
        ->and((float) $onHand())->toBe(17.0);

    // Concluída: nova execução não faz nada.
    (new ApplyCsvImport($import->id, $import->tenant_id, $this->owner->id))->handle(app(App\Support\Tenancy\TenantDatabase::class), app(App\Services\Integrations\Imports\ImportKinds::class), app(App\Support\Auth\Access::class), app(App\Support\Clock\Clock::class));
    // Interrompida no meio: só as linhas ainda válidas seriam aplicadas.
    $this->scenario->system(fn () => CsvImport::query()->whereKey($import->id)->update(['status' => 'processing']));
    dispatch(new ApplyCsvImport($import->id, $import->tenant_id, $this->owner->id));

    expect((float) $onHand())->toBe(17.0)->and(($this->fresh)($import)[0]->status)->toBe('done');
});

it('exige integrations.manage e a permissão do tipo importado', function (): void {
    $warehouse = $this->scenario->tenant->addStaff(SystemRole::Warehouse);

    expect(fn () => ($this->imports)(fn (CsvImports $i) => $i->preview('stock', 'valid_only', 'a.csv', "deposito;codigo_sku;quantidade\nCD1;X;1\n"), $warehouse))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->preview)('pedidos', 'a'))->toThrow(InvalidIntegration::class, 'tipo')
        ->and(fn () => ($this->preview)('stock', "deposito;codigo_sku;quantidade\nCD1;X;1\n", 'parcial'))->toThrow(InvalidIntegration::class, 'política');
});
