<?php

declare(strict_types=1);

namespace App\Data\Identity;

use App\Enums\Identity\MembershipKind;

final readonly class InviteMemberData
{
    public function __construct(
        public string $email,
        public MembershipKind $kind,
        public string $roleId,
        public ?string $buyerCompanyId = null,
        public ?string $branchId = null,
    ) {}
}
