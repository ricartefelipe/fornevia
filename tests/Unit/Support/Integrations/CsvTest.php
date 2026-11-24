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

it('recusa arquivo vazio, grande demais, fora de UTF-8, sem colunas ou sem dados', function (string $contents, int $maxBytes, string $message): void {
    expect(fn () => Csv::read($contents, ['a', 'b'], $maxBytes, 10))->toThrow(InvalidIntegration::class, $message);
})->with([
    'vazio' => ['', 1000, 'O arquivo deve ter conteúdo e até 0 MB.'],
    'grande demais' => ["a;b\n1;2\n", 4, 'até 0 MB'],
    'limite em MB' => [str_repeat('x', 3 * 1024 * 1024), 2 * 1024 * 1024, 'até 2 MB.'],
    'fora de UTF-8' => ["a;b\n\xE9;2\n", 1000, 'O arquivo precisa estar em UTF-8.'],
    'coluna ausente' => ["a;c\n1;2\n", 1000, 'Colunas obrigatórias ausentes: b.'],
    'sem dados' => ["a;b\n\n  ;  \n", 1000, 'O arquivo não tem linhas de dados.'],
]);

it('detecta o separador pelo cabeçalho e preenche colunas que faltam na linha', function (): void {
    expect(Csv::read("a,b,c;d\n1,2,3;4\n", ['a', 'b', 'c_d'], 1000, 10))->toBe([2 => ['a' => '1', 'b' => '2', 'c_d' => '3;4']])
        ->and(Csv::read("a;b,c\n1;2,3\n", ['a', 'b_c'], 1000, 10))->toBe([2 => ['a' => '1', 'b_c' => '2,3']])
        ->and(Csv::read("A; B ;Ç d\n1\n", ['a', 'b', 'c_d'], 1000, 10))->toBe([2 => ['a' => '1', 'b' => '', 'c_d' => '']]);
});

it('aceita o arquivo no tamanho exato do limite e aponta o campo em cada recusa', function (): void {
    $csv = "a;b\n1;2\n";

    expect(Csv::read($csv, ['a', 'b'], strlen($csv), 10))->toHaveCount(1);

    foreach ([
        ['', 1024 * 1024 * 1024, 'O arquivo deve ter conteúdo e até 1024 MB.'],
        ["a;b\n\xE9;2\n", 1000, 'O arquivo precisa estar em UTF-8.'],
        ["a;c\n1;2\n", 1000, 'Colunas obrigatórias ausentes: b.'],
        ["a;b\n1;2\n3;4\n", 1000, 'O arquivo passa de 1 linhas.'],
        ["a;b\n", 1000, 'O arquivo não tem linhas de dados.'],
    ] as [$contents, $maxBytes, $message]) {
        try {
            Csv::read($contents, ['a', 'b'], $maxBytes, 1);
            $this->fail('Esperava recusa: '.$message);
        } catch (InvalidIntegration $e) {
            expect($e->getMessage())->toBe($message)->and($e->details())->toBe(['field' => 'file']);
        }
    }
});
