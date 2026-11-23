<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Models\Quotes\Quote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Número de cotação por distribuidor (COT-0000001), no padrão da
 * numeração de pedidos (ADR-022): a cotação nasce com número provisório e
 * recebe o definitivo no fim da transação. Exige contexto de equipe ou de
 * sistema.
 */
final class QuoteNumbering
{
    public const string PROVISIONAL_PREFIX = 'PEND-';

    public static function provisional(): string
    {
        return self::PROVISIONAL_PREFIX.Str::upper(Str::random(15));
    }

    public function assign(Quote $quote): void
    {
        if (! str_starts_with($quote->number, self::PROVISIONAL_PREFIX)) {
            return;
        }

        $row = DB::selectOne(
            'insert into quote_number_counters (tenant_id, last_number, updated_at) values (?, 1, now())
             on conflict (tenant_id) do update set last_number = quote_number_counters.last_number + 1, updated_at = now()
             returning last_number',
            [$quote->tenant_id],
        );

        $quote->forceFill(['number' => 'COT-'.str_pad((string) $row->last_number, 7, '0', STR_PAD_LEFT)])->save();
    }
}
