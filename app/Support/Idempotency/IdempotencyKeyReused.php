<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

use App\Domain\Shared\ConflictingState;

final class IdempotencyKeyReused extends ConflictingState
{
    public function errorCode(): string
    {
        return 'idempotency_key_reused';
    }
}
