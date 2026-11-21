<?php

declare(strict_types=1);

namespace App\Domain\Buyers;

use App\Domain\Buyers\Exceptions\InvalidAddress;

/**
 * CEP com 8 dígitos. Aceita "01310100" ou "01310-100"; armazenado só com
 * dígitos (coluna char(8) com CHECK no banco).
 */
final readonly class PostalCode
{
    private function __construct(public string $digits) {}

    public static function fromString(string $value): self
    {
        $normalized = trim($value);

        if (preg_match('/^\d{5}-?\d{3}$/D', $normalized) !== 1) {
            throw new InvalidAddress('Informe um CEP válido com 8 dígitos.', ['field' => 'postal_code']);
        }

        $digits = str_replace('-', '', $normalized);

        if ($digits === '00000000') {
            throw new InvalidAddress('Informe um CEP válido com 8 dígitos.', ['field' => 'postal_code']);
        }

        return new self($digits);
    }

    public function formatted(): string
    {
        return self::format($this->digits);
    }

    /**
     * Formata 8 dígitos já persistidos, sem revalidar.
     */
    public static function format(string $digits): string
    {
        if (preg_match('/^\d{8}$/D', $digits) !== 1) {
            return $digits;
        }

        return substr($digits, 0, 5).'-'.substr($digits, 5, 3);
    }
}
