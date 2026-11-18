<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal\Support;

use App\Actions\Ordering\Portal\Exceptions\InvalidCartItem;

/**
 * Interpreta o texto colado no pedido rápido: uma linha por item, no
 * formato "CÓDIGO quantidade".
 *
 * - Separadores aceitos entre código e quantidade: espaço, tabulação
 *   (colagem de planilha), ponto e vírgula, vírgula, barra vertical,
 *   dois-pontos ou sinal de igual. O código do SKU não contém nenhum deles,
 *   então "CIM-50,2,5" é lido como código CIM-50 e quantidade 2,5.
 * - Aspas (exportação CSV) e separadores ao final da linha são ignorados.
 * - A quantidade segue o formato brasileiro (PurchaseQuantityAdvisor) e
 *   pode vir seguida do código da unidade ("10 UN"), conferido depois com
 *   a unidade de venda do SKU.
 * - Linhas em branco são ignoradas; o número de cada linha é o do texto
 *   original, para o relatório.
 */
final class QuickOrderParser
{
    public const int MAX_LENGTH = 20_000;

    public const int MAX_LINES = 200;

    private const int MAX_ECHO = 120;

    /**
     * @return list<array{line: int, input: string, code: string|null, quantity: string|null, unit: string|null, error: string|null}>
     *
     * @throws InvalidCartItem texto vazio, longo demais ou com linhas demais
     */
    public function parse(string $text): array
    {
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw InvalidCartItem::because('O texto colado excede o limite de '.number_format(self::MAX_LENGTH, 0, ',', '.').' caracteres. Divida o pedido em partes.', null, 'text');
        }

        $rows = preg_split('/\R/u', $text);

        if ($rows === false) {
            throw InvalidCartItem::because('O texto colado contém caracteres inválidos.', null, 'text');
        }

        $lines = [];

        foreach ($rows as $index => $row) {
            $clean = trim(str_replace(['"', "\u{FEFF}", "\u{00A0}"], ['', '', ' '], $row));

            if ($clean === '') {
                continue;
            }

            if (count($lines) >= self::MAX_LINES) {
                throw InvalidCartItem::because('Informe no máximo '.self::MAX_LINES.' linhas por vez.', null, 'text');
            }

            $lines[] = $this->line($index + 1, $clean);
        }

        if ($lines === []) {
            throw InvalidCartItem::because('Cole ao menos uma linha no formato "CÓDIGO quantidade".', null, 'text');
        }

        return $lines;
    }

    /**
     * @return array{line: int, input: string, code: string|null, quantity: string|null, unit: string|null, error: string|null}
     */
    private function line(int $number, string $text): array
    {
        $echo = mb_substr($text, 0, self::MAX_ECHO);

        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9._\/-]{0,39})(?:\s*[;,|:=]\s*|\s+)(.+)$/Du', $text, $match) !== 1) {
            $error = preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,39}[\s;,|:=]*$/Du', $text) === 1
                ? 'Informe a quantidade depois do código.'
                : 'Linha fora do formato "CÓDIGO quantidade".';

            return ['line' => $number, 'input' => $echo, 'code' => null, 'quantity' => null, 'unit' => null, 'error' => $error];
        }

        $quantity = rtrim($match[2], " \t;,|:=");
        $unit = null;

        if (preg_match('/^(.+?)\s+([A-Za-z]{1,10})$/Du', $quantity, $withUnit) === 1) {
            $quantity = $withUnit[1];
            $unit = mb_strtoupper($withUnit[2]);
        }

        return [
            'line' => $number,
            'input' => $echo,
            'code' => mb_strtoupper($match[1]),
            'quantity' => trim($quantity),
            'unit' => $unit,
            'error' => null,
        ];
    }
}
