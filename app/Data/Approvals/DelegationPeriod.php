<?php

declare(strict_types=1);

namespace App\Data\Approvals;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Vigência de uma delegação em dias comerciais (fuso do negócio), do
 * primeiro ao último dia, inclusive.
 *
 * No banco a vigência é o intervalo [valid_from, valid_until): início do
 * primeiro dia e início do dia seguinte ao último. É o critério usado na
 * decisão (ApprovalEligibility::delegatorsOf: valid_from <= agora <
 * valid_until).
 */
final readonly class DelegationPeriod
{
    /**
     * @param  string  $firstDay  Y-m-d
     * @param  string  $lastDay  Y-m-d
     */
    private function __construct(
        public string $firstDay,
        public string $lastDay,
    ) {}

    /**
     * @throws InvalidArgumentException data fora do formato Y-m-d ou inexistente
     */
    public static function fromDays(string $firstDay, string $lastDay): self
    {
        self::date($firstDay);
        self::date($lastDay);

        return new self($firstDay, $lastDay);
    }

    public static function fromInstants(CarbonImmutable $validFrom, CarbonImmutable $validUntil): self
    {
        $timezone = self::timezone();
        $until = $validUntil->setTimezone($timezone);
        // Fim exclusivo: o último dia é o anterior quando o término cai à meia-noite.
        $last = $until->equalTo($until->startOfDay()) ? $until->subDay() : $until;

        return new self($validFrom->setTimezone($timezone)->toDateString(), $last->toDateString());
    }

    public function validFrom(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->firstDay, self::timezone())->startOfDay()->utc();
    }

    public function validUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->lastDay, self::timezone())->addDay()->startOfDay()->utc();
    }

    /**
     * Quantidade de dias da vigência (primeiro e último inclusive); negativa
     * ou zero quando o último dia é anterior ao primeiro.
     */
    public function days(): int
    {
        return (int) self::date($this->firstDay)->diffInDays(self::date($this->lastDay), false) + 1;
    }

    public function label(): string
    {
        if ($this->firstDay === $this->lastDay) {
            return self::date($this->firstDay)->format('d/m/Y');
        }

        return self::date($this->firstDay)->format('d/m/Y').' a '.self::date($this->lastDay)->format('d/m/Y');
    }

    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'America/Sao_Paulo');
    }

    /**
     * Data em UTC à meia-noite, para aritmética de dias sem efeito de fuso.
     */
    private static function date(string $value): CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException("Data inválida: {$value}");
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, 'UTC')
            ?? throw new InvalidArgumentException("Data inválida: {$value}");
    }
}
