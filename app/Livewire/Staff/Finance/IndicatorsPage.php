<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Finance;

use App\Services\Finance\IndicatorsQuery;
use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Indicadores do período (RF-102): ticket médio, conversão de propostas,
 * margem e inadimplência, com as fórmulas exibidas na tela.
 */
#[Layout('layouts.app')]
#[Title('Indicadores')]
final class IndicatorsPage extends Component
{
    #[Url(as: 'de', except: '')]
    public string $from = '';

    #[Url(as: 'ate', except: '')]
    public string $until = '';

    public function render(IndicatorsQuery $query, Clock $clock): View
    {
        $tz = (string) config('app.business_timezone');
        $today = $clock->now()->setTimezone($tz);
        $from = self::date($this->from, $tz) ?? $today->startOfMonth();
        $until = self::date($this->until, $tz) ?? $today;

        if ($until->lessThan($from)) {
            [$from, $until] = [$until, $from];
        }

        return view('livewire.staff.finance.indicators-page', [
            'data' => $query->for($from, $until, $today),
            'periodFrom' => $from,
            'periodUntil' => $until,
        ]);
    }

    private static function date(string $value, string $tz): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, $tz)?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
