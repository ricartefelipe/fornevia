<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Domain\Quotes\QuoteTerms;

/**
 * Regras de prazo das cotações a partir da configuração (config/quotes.php
 * e fuso comercial).
 */
final class QuoteSettings
{
    public static function terms(): QuoteTerms
    {
        return new QuoteTerms(
            (string) config('app.business_timezone', 'America/Sao_Paulo'),
            (int) config('quotes.max_validity_days', 90),
            (int) config('quotes.max_hold_days', 7),
        );
    }
}
