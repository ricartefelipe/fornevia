<?php

declare(strict_types=1);

namespace App\Data\Approvals;

use Carbon\CarbonImmutable;

/**
 * Delegação na listagem: titular, delegado, vigência em dias, motivo,
 * responsável e situação no momento da consulta.
 */
final readonly class ApprovalDelegationEntry
{
    public const string ACTIVE = 'active';

    public const string SCHEDULED = 'scheduled';

    public const string ENDED = 'ended';

    public const string REVOKED = 'revoked';

    public function __construct(
        public string $id,
        public string $delegatorName,
        public string $delegateName,
        public DelegationPeriod $period,
        public string $reason,
        public string $createdByName,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $revokedAt,
        public string $state,
        public bool $canRevoke,
    ) {}

    public static function stateAt(CarbonImmutable $now, CarbonImmutable $validFrom, CarbonImmutable $validUntil, ?CarbonImmutable $revokedAt): string
    {
        return match (true) {
            $revokedAt !== null => self::REVOKED,
            $validUntil->lessThanOrEqualTo($now) => self::ENDED,
            $validFrom->greaterThan($now) => self::SCHEDULED,
            default => self::ACTIVE,
        };
    }

    public function isOpen(): bool
    {
        return $this->state === self::ACTIVE || $this->state === self::SCHEDULED;
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::ACTIVE => 'Vigente',
            self::SCHEDULED => 'Futura',
            self::REVOKED => 'Revogada',
            default => 'Encerrada',
        };
    }

    public function stateColor(): string
    {
        return match ($this->state) {
            self::ACTIVE => 'green',
            self::SCHEDULED => 'blue',
            self::REVOKED => 'red',
            default => 'slate',
        };
    }
}
