<?php

declare(strict_types=1);

namespace App\Data\Credit;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Exceção de limite exibida no detalhe da conta, com a situação no
 * instante da consulta: agendada, vigente, encerrada ou revogada.
 */
final readonly class CreditExceptionEntry
{
    public const string SCHEDULED = 'scheduled';

    public const string ACTIVE = 'active';

    public const string EXPIRED = 'expired';

    public const string REVOKED = 'revoked';

    public function __construct(
        public string $id,
        public Money $amount,
        public CreditExceptionPeriod $period,
        public string $reason,
        public string $grantedBy,
        public string $state,
        public ?CarbonImmutable $revokedAt,
        public ?string $revokedBy,
        public ?string $revocationReason,
    ) {}

    public static function stateAt(CarbonImmutable $now, CarbonImmutable $validFrom, CarbonImmutable $validUntil, ?CarbonImmutable $revokedAt): string
    {
        return match (true) {
            $revokedAt !== null => self::REVOKED,
            $validUntil->lessThanOrEqualTo($now) => self::EXPIRED,
            $validFrom->greaterThan($now) => self::SCHEDULED,
            default => self::ACTIVE,
        };
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::SCHEDULED => 'Agendada',
            self::ACTIVE => 'Vigente',
            self::EXPIRED => 'Encerrada',
            default => 'Revogada',
        };
    }

    public function stateColor(): string
    {
        return match ($this->state) {
            self::SCHEDULED => 'blue',
            self::ACTIVE => 'green',
            default => 'slate',
        };
    }

    /**
     * Exceções vigentes e agendadas podem ser revogadas.
     */
    public function isOpen(): bool
    {
        return $this->state === self::ACTIVE || $this->state === self::SCHEDULED;
    }
}
