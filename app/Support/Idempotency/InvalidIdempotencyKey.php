<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

use App\Domain\Shared\BusinessRuleViolation;

final class InvalidIdempotencyKey extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_idempotency_key';
    }
}
