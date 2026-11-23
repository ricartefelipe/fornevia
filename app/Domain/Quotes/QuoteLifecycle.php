<?php

declare(strict_types=1);

namespace App\Domain\Quotes;

use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use LogicException;

/**
 * Transições permitidas da cotação e da versão (ADR-025). Os models Quote e
 * QuoteVersion conferem toda mudança de situação antes de gravar (erro de
 * programação, LogicException); os triggers quote_guard e
 * quote_version_guard aplicam o mesmo mapa no banco.
 */
final class QuoteLifecycle
{
    /** @var array<string, list<string>> */
    private const array QUOTE = [
        'requested' => ['proposed', 'cancelled'],
        'proposed' => ['requested', 'expired', 'accepted', 'rejected', 'cancelled'],
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

    public static function assertQuoteMove(QuoteStatus $from, QuoteStatus $to): void
    {
        if ($from !== $to && ! self::canMoveQuote($from, $to)) {
            throw new LogicException("Cotação: transição {$from->value} para {$to->value} não permitida.");
        }
    }

    public static function assertVersionMove(QuoteVersionStatus $from, QuoteVersionStatus $to): void
    {
        if ($from !== $to && ! self::canMoveVersion($from, $to)) {
            throw new LogicException("Versão da cotação: transição {$from->value} para {$to->value} não permitida.");
        }
    }
}
