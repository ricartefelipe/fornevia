<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Produto ativo precisa de ao menos um SKU ativo (ativação ou inativação
 * do último SKU ativo).
 */
final class ProductRequiresActiveSku extends BusinessRuleViolation
{
    public static function toActivate(): self
    {
        return new self('Cadastre ou ative ao menos um SKU antes de ativar o produto.');
    }

    public static function toKeepActive(): self
    {
        return new self('Este é o último SKU ativo de um produto ativo. Inative o produto antes de inativar ou arquivar o SKU.');
    }

    public function errorCode(): string
    {
        return 'product_requires_active_sku';
    }
}
