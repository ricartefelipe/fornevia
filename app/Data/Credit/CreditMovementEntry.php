<?php

declare(strict_types=1);

namespace App\Data\Credit;

use App\Domain\Shared\Money;
use App\Enums\Credit\CreditMovementType;
use Carbon\CarbonImmutable;

/**
 * Movimento do ledger de crédito exibido no histórico da conta.
 */
final readonly class CreditMovementEntry
{
    public function __construct(
        public string $id,
        public CreditMovementType $type,
        public Money $committedDelta,
        public Money $receivableDelta,
        public string $reference,
        public ?string $reason,
        public string $actor,
        public CarbonImmutable $createdAt,
    ) {}

    /**
     * Valor com sinal explícito ("+R$ 10,00", "-R$ 10,00", "R$ 0,00").
     */
    public static function signed(Money $value): string
    {
        return $value->isPositive() ? '+'.$value->format() : $value->format();
    }
}
