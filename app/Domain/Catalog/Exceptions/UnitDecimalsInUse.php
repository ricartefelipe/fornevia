<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Redução de casas decimais de uma unidade em uso: como unidade de venda
 * (mínimo, múltiplo, faixas de preço) ou como unidade base (quantidades
 * convertidas) de SKUs que deixariam de ser representáveis.
 */
final class UnitDecimalsInUse extends ConflictingState
{
    /**
     * @param  list<string>  $skuCodes
     */
    public static function forSkus(array $skuCodes): self
    {
        return new self(
            'Há SKUs cujo mínimo, múltiplo, faixa de preço ou quantidade convertida para a unidade base deixaria de ser representável com a nova quantidade de casas decimais: '.implode(', ', $skuCodes).'.',
            ['sku_codes' => $skuCodes],
        );
    }

    public function errorCode(): string
    {
        return 'unit_decimals_in_use';
    }
}
