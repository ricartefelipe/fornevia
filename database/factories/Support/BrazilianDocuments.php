<?php

declare(strict_types=1);

namespace Database\Factories\Support;

/**
 * Geração de documentos brasileiros com dígitos verificadores válidos para
 * dados de teste e demonstração (não correspondem a empresas reais).
 */
final class BrazilianDocuments
{
    public static function cnpj(): string
    {
        $base = '';

        for ($i = 0; $i < 8; $i++) {
            $base .= (string) random_int(0, 9);
        }

        $base .= '0001';

        return $base.self::cnpjDigit($base).self::cnpjDigit($base.self::cnpjDigit($base));
    }

    private static function cnpjDigit(string $digits): string
    {
        $weights = strlen($digits) === 12
            ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
            : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $sum = 0;

        foreach (str_split($digits) as $index => $digit) {
            $sum += (int) $digit * $weights[$index];
        }

        $rest = $sum % 11;

        return (string) ($rest < 2 ? 0 : 11 - $rest);
    }
}
