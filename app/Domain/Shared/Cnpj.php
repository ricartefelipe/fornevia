<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * CNPJ com validação dos dígitos verificadores. Armazenado só com dígitos.
 */
final readonly class Cnpj
{
    private function __construct(public string $digits) {}

    public static function fromString(string $value): self
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        if (! self::isValid($digits)) {
            throw new InvalidDocument('CNPJ inválido.', ['field' => 'document']);
        }

        return new self($digits);
    }

    public static function isValid(string $digits): bool
    {
        if (preg_match('/^\d{14}$/D', $digits) !== 1 || preg_match('/^(\d)\1{13}$/D', $digits) === 1) {
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
     * Formata 14 dígitos já persistidos, sem revalidar.
     */
    public static function format(string $digits): string
    {
        if (preg_match('/^\d{14}$/D', $digits) !== 1) {
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
            $sum += (int) $char * $weights[$i];
        }

        $rest = $sum % 11;

        return (string) ($rest < 2 ? 0 : 11 - $rest);
    }
}
