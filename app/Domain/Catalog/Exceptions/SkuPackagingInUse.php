<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Troca de unidade de venda ou fator de conversão de um SKU que já tem
 * preços: preços, faixas e condições negociadas estão na unidade de venda,
 * e passariam a valer para outra embalagem. Uma nova embalagem é um novo SKU.
 */
final class SkuPackagingInUse extends ConflictingState
{
    public static function priced(): self
    {
        return new self(
            'Este SKU já tem preços em tabelas ou condições negociadas. Para vender em outra unidade ou embalagem, cadastre um novo SKU.',
            ['field' => 'sales_unit_id'],
        );
    }

    public function errorCode(): string
    {
        return 'sku_packaging_in_use';
    }
}
