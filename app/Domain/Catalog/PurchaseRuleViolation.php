<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

enum PurchaseRuleViolation: string
{
    case NotPositive = 'not_positive';
    case TooManyDecimals = 'too_many_decimals';
    case BelowMinimum = 'below_minimum';
    case NotMultiple = 'not_multiple';
}
