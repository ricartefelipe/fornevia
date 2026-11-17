<?php

declare(strict_types=1);

namespace App\Domain\Shared;

final class InvalidDocument extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_document';
    }
}
