<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Mudança de situação não permitida a partir da situação atual do produto.
 */
final class InvalidProductStatusTransition extends ConflictingState
{
    public static function between(string $from, string $to, string $fromLabel, string $toLabel): self
    {
        return new self(
            "Não é possível alterar a situação do produto de {$fromLabel} para {$toLabel}.",
            ['from' => $from, 'to' => $to],
        );
    }

    public function errorCode(): string
    {
        return 'invalid_product_status_transition';
    }
}
