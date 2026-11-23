<?php

declare(strict_types=1);

namespace App\Domain\Quotes;

use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;

/**
 * Transições permitidas da cotação e da versão (ADR-025). As Actions
 * conferem antes de gravar; os triggers do banco aplicam o mesmo mapa.
 */
final class QuoteLifecycle
{
    /** @var array<string, list<string>> */
    private const array QUOTE = [
        'requested' => ['proposed', 'cancelled'],
        'proposed' => ['proposed', 'requested', 'expired', 'accepted', 'rejected', 'cancelled'],
        'expired' => ['proposed', 'requested', 'cancelled'],
        'accepted' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    /** @var array<string, list<string>> */
    private const array VERSION = [
        'pending_approval' => ['ready', 'approval_rejected', 'withdrawn', 'expired'],
        'ready' => ['sent', 'withdrawn', 'expired'],
        'sent' => ['accepted', 'superseded', 'countered', 'rejected', 'expired', 'withdrawn'],
        'accepted' => [],
        'superseded' => [],
        'countered' => [],
        'rejected' => [],
        'expired' => [],
        'withdrawn' => [],
        'approval_rejected' => [],
    ];

    public static function canMoveQuote(QuoteStatus $from, QuoteStatus $to): bool
    {
        return in_array($to->value, self::QUOTE[$from->value], true);
    }

    public static function canMoveVersion(QuoteVersionStatus $from, QuoteVersionStatus $to): bool
    {
        return in_array($to->value, self::VERSION[$from->value], true);
    }
}
