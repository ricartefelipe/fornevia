<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Enums\Quotes\QuoteCloseReason;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteVersion;
use App\Support\Clock\Clock;
use Illuminate\Database\Eloquent\Collection;

/**
 * Encerramento da cotação (recusa, cancelamento, encerramento pela
 * equipe): versões abertas deixam de valer, reservas são liberadas e a
 * cotação fica na situação final com o motivo. A cotação e as versões já
 * estão bloqueadas.
 */
final readonly class QuoteClosing
{
    public function __construct(
        private QuoteStockHolds $holds,
        private Clock $clock,
    ) {}

    /**
     * Motivo validado: o código precisa existir e "outro" (ou o
     * encerramento pela equipe) exige texto.
     *
     * @return array{0: QuoteCloseReason, 1: string|null}
     */
    public static function reason(string $code, ?string $text, bool $staff = false): array
    {
        $reason = QuoteCloseReason::tryFrom($code);
        $text = $text !== null && trim($text) !== '' ? trim($text) : null;

        if ($reason === null || ($reason === QuoteCloseReason::StaffClosed) !== $staff) {
            throw new InvalidQuote('Escolha o motivo.', ['field' => 'reason_code']);
        }

        if (in_array($reason, [QuoteCloseReason::Other, QuoteCloseReason::StaffClosed], true) && mb_strlen((string) $text) < 5) {
            throw new InvalidQuote('Descreva o motivo (ao menos 5 caracteres).', ['field' => 'reason']);
        }

        if ($text !== null && mb_strlen($text) > 500) {
            throw new InvalidQuote('O motivo deve ter até 500 caracteres.', ['field' => 'reason']);
        }

        return [$reason, $text];
    }

    /**
     * @param  Collection<int, QuoteVersion>  $versions
     */
    public function close(Quote $quote, Collection $versions, QuoteStatus $status, QuoteVersionStatus $sentBecomes, StockHoldEndReason $holdReason, QuoteCloseReason $reason, ?string $text, ?string $userId): void
    {
        $now = $this->clock->now();

        foreach ($versions->filter(static fn (QuoteVersion $v): bool => $v->status->isOpen()) as $version) {
            $this->holds->release($version, $holdReason);
            $version->forceFill([
                'status' => $version->status === QuoteVersionStatus::Sent ? $sentBecomes : QuoteVersionStatus::Withdrawn,
                'closed_at' => $now,
            ])->save();
        }

        $quote->forceFill([
            'status' => $status,
            'closed_at' => $now,
            'closed_by_user_id' => $userId,
            'close_reason_code' => $reason,
            'close_reason' => $text,
            'last_activity_at' => $now,
        ])->save();
    }
}
