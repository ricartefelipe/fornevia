<?php

declare(strict_types=1);

namespace App\Actions\Inventory\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * O depósito tem movimentos, saldos ou reservas (ou é referenciado por
 * outros registros) e, por isso, não pode ser excluído: o histórico do
 * estoque precisa continuar rastreável (RN-070).
 */
final class WarehouseHasHistory extends ConflictingState
{
    public static function create(string $code): self
    {
        return new self(
            "O depósito {$code} possui histórico de estoque e não pode ser excluído. Desative-o para retirá-lo das vendas e das entradas.",
            ['recommendation' => 'deactivate'],
        );
    }

    public function errorCode(): string
    {
        return 'warehouse_has_history';
    }
}
