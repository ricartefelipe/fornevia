<?php

declare(strict_types=1);

use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Support\Integrations\Csv;

/*
| CSV (RF-113, RS-110): leitura tolerante a separador, BOM, aspas e
| cabeçalhos acentuados; escrita que neutraliza fórmulas de planilha.
*/

it('neutraliza textos que planilhas executariam como fórmula', function (mixed $value, string $expected): void {
    expect(Csv::cell($value))->toBe($expected);
})->with([
    'igual' => ['=HYPERLINK("http://x")', "'=HYPERLINK(\"http://x\")"],
    'mais' => ['+1+2', "'+1+2"],
    'menos' => ['-2+3', "'-2+3"],
    'arroba' => ['@SUM(A1)', "'@SUM(A1)"],
    'tabulação' => ["\tcmd", "'\tcmd"],
    'retorno' => ["\rcmd", "'\rcmd"],
    'texto comum' => ['Cabo 2,5 mm', 'Cabo 2,5 mm'],
    'número negativo' => [-10, '-10'],
    'decimal' => [12.5, '12.5'],
    'nulo' => [null, ''],
]);

it('escreve a linha com ponto e vírgula e aspas quando preciso', function (): void {
    expect(Csv::line(['PED-1', 'Alfa; Beta', 'diz "oi"', '=1+1', 300]))
        ->toBe("PED-1;\"Alfa; Beta\";\"diz \"\"oi\"\"\";'=1+1;300\r\n");
});

it('lê com BOM, vírgula, aspas com quebra de linha e cabeçalho acentuado', function (): void {
    $rows = Csv::read("\u{FEFF}Razão Social,Código\r\n\"Alfa, Ltda\",\"A\nB\"\r\n\r\n  Beta  ,C\r\n", ['razao_social', 'codigo'], 1000, 10);

    expect($rows)->toBe([
        2 => ['razao_social' => 'Alfa, Ltda', 'codigo' => "A\nB"],
        4 => ['razao_social' => 'Beta', 'codigo' => 'C'],
    ]);
});

it('conta só as linhas de dados no limite', function (): void {
    expect(Csv::read("a;b\n1;2\n\n3;4\n", ['a', 'b'], 1000, 2))->toHaveCount(2)
        ->and(fn () => Csv::read("a;b\n1;2\n3;4\n5;6\n", ['a', 'b'], 1000, 2))->toThrow(InvalidIntegration::class, '2 linhas');
});
