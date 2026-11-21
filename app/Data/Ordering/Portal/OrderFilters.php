<?php

declare(strict_types=1);

namespace App\Data\Ordering\Portal;

use App\Enums\Ordering\CommercialStatus;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Filtros da lista de pedidos do comprador, já normalizados.
 *
 * Os valores chegam da tela ou da URL (entrada não confiável): situação
 * desconhecida e datas fora do formato AAAA-MM-DD ou do intervalo de
 * 2000-01-01 a 2099-12-31 são ignoradas. Datas são dias no fuso comercial.
 */
final readonly class OrderFilters
{
    public const string MIN_DATE = '2000-01-01';

    public const string MAX_DATE = '2099-12-31';

    public function __construct(
        public ?CommercialStatus $status = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $until = null,
        public bool $onlyMine = false,
    ) {}

    public static function fromInput(mixed $status, mixed $from, mixed $until, mixed $onlyMine = false): self
    {
        $from = self::date($from);
        $until = self::date($until);

        // Período invertido: considera as datas na ordem cronológica.
        if ($from !== null && $until !== null && $from->greaterThan($until)) {
            [$from, $until] = [$until, $from];
        }

        return new self(
            is_string($status) ? CommercialStatus::tryFrom($status) : null,
            $from,
            $until,
            $onlyMine === true,
        );
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        if ($value < self::MIN_DATE || $value > self::MAX_DATE) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('app.business_timezone', 'America/Sao_Paulo'));
        } catch (Throwable) {
            return null;
        }

        // Datas inexistentes (ex.: 2026-02-30) não são aceitas por ajuste.
        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    public function isFiltered(): bool
    {
        return $this->status !== null || $this->from !== null || $this->until !== null || $this->onlyMine;
    }
}
