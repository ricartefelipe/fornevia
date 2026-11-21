<?php

declare(strict_types=1);

namespace App\Domain\Buyers;

use App\Domain\Buyers\Exceptions\JustificationRequired;

/**
 * Justificativa obrigatória de alterações sensíveis (situação da empresa),
 * registrada na auditoria.
 */
final readonly class Justification
{
    public const int MIN_LENGTH = 5;

    public const int MAX_LENGTH = 500;

    private function __construct(public string $text) {}

    public static function fromString(string $value): self
    {
        $text = trim($value);
        $length = mb_strlen($text);

        if ($length < self::MIN_LENGTH) {
            throw new JustificationRequired(
                'Informe a justificativa (mínimo de '.self::MIN_LENGTH.' caracteres).',
                ['field' => 'reason'],
            );
        }

        if ($length > self::MAX_LENGTH) {
            throw new JustificationRequired(
                'A justificativa deve ter no máximo '.self::MAX_LENGTH.' caracteres.',
                ['field' => 'reason'],
            );
        }

        return new self($text);
    }
}
