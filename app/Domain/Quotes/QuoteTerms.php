<?php

declare(strict_types=1);

namespace App\Domain\Quotes;

use App\Domain\Quotes\Exceptions\InvalidQuote;
use Carbon\CarbonImmutable;

/**
 * Validade da proposta e prazo da reserva de estoque, em dias comerciais
 * (fuso do negócio): a proposta vale até o fim do dia escolhido, entre hoje
 * e hoje + máximo de dias; a reserva vale até o fim do dia escolhido, entre
 * hoje e o menor entre o dia da validade e hoje + máximo da reserva. Os
 * instantes são devolvidos em UTC.
 */
final readonly class QuoteTerms
{
    public function __construct(
        private string $timezone,
        private int $maxValidityDays,
        private int $maxHoldDays,
    ) {}

    /**
     * @param  string  $day  Y-m-d
     */
    public function validUntil(string $day, CarbonImmutable $now): CarbonImmutable
    {
        $chosen = $this->day($day, 'valid_until', 'Informe a validade da proposta.');
        $today = $now->setTimezone($this->timezone)->startOfDay();

        if ($chosen->lessThan($today)) {
            throw new InvalidQuote('A validade não pode terminar antes de hoje.', ['field' => 'valid_until']);
        }

        if ($chosen->greaterThan($today->addDays($this->maxValidityDays))) {
            throw new InvalidQuote("A validade pode ser de até {$this->maxValidityDays} dias.", ['field' => 'valid_until']);
        }

        return $chosen->endOfDay()->setTimezone('UTC');
    }

    /**
     * @param  string  $day  Y-m-d
     */
    public function holdUntil(string $day, CarbonImmutable $validUntil, CarbonImmutable $now): CarbonImmutable
    {
        $chosen = $this->day($day, 'stock_hold_until', 'Informe até quando reservar o estoque.');
        $today = $now->setTimezone($this->timezone)->startOfDay();
        $validDay = $validUntil->setTimezone($this->timezone)->startOfDay();

        if ($chosen->lessThan($today)) {
            throw new InvalidQuote('A reserva não pode terminar antes de hoje.', ['field' => 'stock_hold_until']);
        }

        if ($chosen->greaterThan($validDay)) {
            throw new InvalidQuote('A reserva não pode ir além da validade da proposta.', ['field' => 'stock_hold_until']);
        }

        if ($chosen->greaterThan($today->addDays($this->maxHoldDays))) {
            throw new InvalidQuote("A reserva pode ser de até {$this->maxHoldDays} dias.", ['field' => 'stock_hold_until']);
        }

        return $chosen->endOfDay()->setTimezone('UTC');
    }

    /**
     * Dia padrão da validade (hoje + dias padrão), em Y-m-d.
     */
    public function defaultValidityDay(CarbonImmutable $now, int $days): string
    {
        return $now->setTimezone($this->timezone)->addDays(min($days, $this->maxValidityDays))->toDateString();
    }

    private function day(string $day, string $field, string $message): CarbonImmutable
    {
        $day = trim($day);

        if (preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/D', $day, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidQuote($message, ['field' => $field]);
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->timezone)
            ?: throw new InvalidQuote($message, ['field' => $field]);
    }
}
