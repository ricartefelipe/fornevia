<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Tabela arquivada: cabeçalho e itens não podem mais ser alterados.
 */
final class PriceListNotEditable extends ConflictingState
{
    public function errorCode(): string
    {
        return 'price_list_not_editable';
    }
}
