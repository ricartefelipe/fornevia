<?php

declare(strict_types=1);

namespace App\Data\Identity;

use App\Enums\Identity\MembershipKind;

final readonly class ContextOption
{
    public function __construct(
        public string $membershipId,
        public string $organizationName,
        public MembershipKind $kind,
        public string $roleName,
        public ?string $buyerCompanyName,
        public bool $available,
    ) {}

    public function label(): string
    {
        return $this->kind === MembershipKind::Buyer
            ? "{$this->buyerCompanyName} · compras em {$this->organizationName}"
            : "{$this->organizationName} · equipe";
    }
}
