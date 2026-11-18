<?php

declare(strict_types=1);

namespace App\Data\Credit;

use App\Domain\Shared\Money;
use App\Enums\Credit\CreditAccountStatus;
use Carbon\CarbonImmutable;

/**
 * Registro do histórico de limite e situação (credit_limit_changes).
 */
final readonly class CreditLimitChangeEntry
{
    public function __construct(
        public string $id,
        public Money $previousLimit,
        public Money $newLimit,
        public CreditAccountStatus $previousStatus,
        public CreditAccountStatus $newStatus,
        public string $reason,
        public string $changedBy,
        public CarbonImmutable $createdAt,
    ) {}

    public function limitChanged(): bool
    {
        return ! $this->previousLimit->equals($this->newLimit);
    }

    public function statusChanged(): bool
    {
        return $this->previousStatus !== $this->newStatus;
    }

    public function description(): string
    {
        $parts = [];

        if ($this->limitChanged()) {
            $parts[] = 'Limite de '.$this->previousLimit->format().' para '.$this->newLimit->format();
        }

        if ($this->statusChanged()) {
            $parts[] = $this->newStatus === CreditAccountStatus::Blocked ? 'Crédito bloqueado' : 'Crédito desbloqueado';
        }

        return $parts === [] ? 'Limite mantido em '.$this->newLimit->format() : implode(' · ', $parts);
    }
}
