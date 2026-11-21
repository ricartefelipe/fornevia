<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Valor monetário ou quantidade fora do intervalo suportado (colunas
 * bigint em centavos e numeric(18,3)). Recusado como erro de negócio, nunca
 * como estouro numérico.
 */
final class AmountOutOfRange extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'amount_out_of_range';
    }
}
