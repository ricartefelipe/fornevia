<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A unidade de venda ou o fator de conversão do SKU mudou entre a abertura
 * do formulário de preço e a gravação: o preço digitado se referia a outra
 * embalagem.
 */
final class SkuPackagingChanged extends ConflictingState
{
    public static function forSku(string $code, string $unitCode): self
    {
        return new self(
            "A embalagem do SKU {$code} mudou enquanto o preço era editado. Confira o preço por {$unitCode} e salve novamente.",
            ['field' => 'unit_price'],
        );
    }

    public function errorCode(): string
    {
        return 'sku_packaging_changed';
    }
}
