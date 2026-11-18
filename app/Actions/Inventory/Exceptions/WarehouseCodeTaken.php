<?php

declare(strict_types=1);

namespace App\Actions\Inventory\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Código de depósito já utilizado no tenant.
 */
final class WarehouseCodeTaken extends ConflictingState
{
    public static function for(string $code): self
    {
        return new self("Já existe um depósito com o código {$code}.", ['field' => 'code', 'value' => $code]);
    }

    public function errorCode(): string
    {
        return 'warehouse_code_taken';
    }
}
