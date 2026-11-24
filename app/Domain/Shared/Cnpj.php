<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * CNPJ com validação dos dígitos verificadores, no formato numérico e no
 * alfanumérico emitido pela Receita Federal a partir de julho de 2026: 12
 * caracteres de raiz e ordem (0-9 e A-Z) e 2 dígitos verificadores
 * numéricos, calculados pelo módulo 11 com o valor de cada caractere igual
 * ao código ASCII menos 48. Armazenado sem pontuação, em maiúsculas.
 */
final readonly class Cnpj
{
    private function __construct(public string $digits) {}

    public static function fromString(string $value): self
    {
        $digits = self::normalize($value);

        if (! self::isValid($digits)) {
            throw new InvalidDocument('CNPJ inválido.', ['field' => 'document']);
        }

        return new self($digits);
    }

    public static function isValid(string $digits): bool
    {
        if (preg_match('/^[0-9A-Z]{12}\d{2}$/D', $digits) !== 1 || preg_match('/^(\d)\1{13}$/D', $digits) === 1) {
            return false;
        }

        return self::digit(substr($digits, 0, 12)) === $digits[12]
            && self::digit(substr($digits, 0, 13)) === $digits[13];
    }

    public function formatted(): string
    {
        return self::format($this->digits);
    }

    /**
     * Formata os 14 caracteres já persistidos, sem revalidar.
     */
    public static function format(string $digits): string
    {
        if (preg_match('/^[0-9A-Z]{12}\d{2}$/D', $digits) !== 1) {
            return $digits;
        }

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($digits, 0, 2),
            substr($digits, 2, 3),
            substr($digits, 5, 3),
            substr($digits, 8, 4),
            substr($digits, 12, 2),
        );
    }

    private static function digit(string $base): string
    {
        $weights = strlen($base) === 12
            ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
            : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach (str_split($base) as $i => $char) {
            $sum += (ord($char) - 48) * $weights[$i];
        }

        $rest = $sum % 11;

        return (string) ($rest < 2 ? 0 : 11 - $rest);
    }

    /** Sem pontuação nem espaços, em maiúsculas. */
    public static function normalize(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[\s.\/-]+/u', '', $value));
    }

    /**
     * Chave para buscar pelo documento quando o termo parece um CNPJ (letras,
     * números e a pontuação do documento, com ao menos um número); null
     * quando não parece.
     */
    public static function searchKey(string $term): ?string
    {
        if (preg_match('/^[0-9A-Za-z.\/\s-]+$/D', $term) !== 1 || preg_match('/\d/', $term) !== 1) {
            return null;
        }

        return self::normalize($term);
    }
}
