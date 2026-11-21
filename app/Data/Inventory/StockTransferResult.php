<?php

declare(strict_types=1);

namespace App\Data\Inventory;

/**
 * Resultado de uma transferência: identificador comum aos dois movimentos
 * e os saldos de origem e destino após a operação.
 */
final readonly class StockTransferResult
{
    public function __construct(
        public string $transferId,
        public StockBalance $from,
        public StockBalance $to,
    ) {}
}
