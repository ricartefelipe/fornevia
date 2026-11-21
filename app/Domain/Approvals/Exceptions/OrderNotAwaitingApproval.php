<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Exceptions;

use App\Domain\Shared\ConflictingState;

final class OrderNotAwaitingApproval extends ConflictingState
{
    public function errorCode(): string
    {
        return 'order_not_awaiting_approval';
    }
}
