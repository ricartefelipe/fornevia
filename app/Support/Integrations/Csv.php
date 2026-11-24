<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Domain\Integrations\Exceptions\InvalidIntegration;

/**
 * Leitura e escrita de CSV (RF-113, RS-110).
 *
 * Leitura: UTF-8 (BOM removido), separador ";" ou "," detectado no
 * cabeçalho, cabeçalhos normalizados (minúsculas, sem acento, espaços
 * viram "_"), linhas vazias ignoradas, limites de tamanho e de linhas.
 *
 * Escrita: textos que começam com =, +, -, @, tabulação ou retorno ganham
 * um apóstrofo, para planilhas não os executarem como fórmula; números
 * saem como estão.
 */
final class Csv
{
    /**
     * @param  list<string>  $required  colunas obrigatórias
     * @return array<int, array<string, string>> número do registro no arquivo (o cabeçalho é o 1) => coluna => valor
     */
    public static function read(string $contents, array $required, int $maxBytes, int $maxRows): array
    {
        if ($contents === '' || strlen($contents) > $maxBytes) {
            throw new InvalidIntegration('O arquivo deve ter conteúdo e até '.intdiv($maxBytes, 1024 * 1024).' MB.', ['field' => 'file']);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidIntegration('O arquivo precisa estar em UTF-8.', ['field' => 'file']);
        }

        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $firstLine = strtok($contents, "\r\n");
        $delimiter = substr_count((string) $firstLine, ';') >= substr_count((string) $firstLine, ',') ? ';' : ',';

        // fgetcsv respeita campos entre aspas com quebra de linha.
        $stream = fopen('php://temp', 'r+b');
        assert($stream !== false);
        fwrite($stream, $contents);
        rewind($stream);
        $header = fgetcsv($stream, null, $delimiter, '"', '');
        $columns = array_map(static fn (?string $c): string => self::normalize((string) $c), $header === false ? [] : $header);
        $missing = array_diff($required, $columns);

        if ($missing !== []) {
            fclose($stream);

            throw new InvalidIntegration('Colunas obrigatórias ausentes: '.implode(', ', $missing).'.', ['field' => 'file']);
        }

        $rows = [];
        $record = 1;

        while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $record++;

            if ($values === [null] || implode('', array_map(static fn (?string $v): string => trim((string) $v), $values)) === '') {
                continue;
            }

            if (count($rows) >= $maxRows) {
                fclose($stream);

                throw new InvalidIntegration("O arquivo passa de {$maxRows} linhas.", ['field' => 'file']);
            }

            $row = [];

            foreach ($columns as $position => $column) {
                $row[$column] = trim((string) ($values[$position] ?? ''));
            }

            $rows[$record] = $row;
        }

        fclose($stream);

        if ($rows === []) {
            throw new InvalidIntegration('O arquivo não tem linhas de dados.', ['field' => 'file']);
        }

        return $rows;
    }

    /** Neutraliza a célula contra execução como fórmula em planilhas. */
    public static function cell(string|int|float|null $value): string
    {
        // Número não é fórmula: -10 continua -10.
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /**
     * @param  list<string|int|float|null>  $cells
     */
    public static function line(array $cells): string
    {
        return implode(';', array_map(static function (string|int|float|null $cell): string {
            $text = self::cell($cell);

            return preg_match('/[;"\n\r]/', $text) === 1 ? '"'.str_replace('"', '""', $text).'"' : $text;
        }, $cells))."\r\n";
    }

    private static function normalize(string $column): string
    {
        $ascii = strtr(mb_strtolower(trim($column)), ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return (string) preg_replace('/[^a-z0-9]+/', '_', $ascii);
    }
}
