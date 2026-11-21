<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Models\Ordering\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Número de pedido por distribuidor (ADR-022). O pedido nasce com número
 * provisório; o definitivo é atribuído no fim da transação, para que o
 * contador por tenant fique bloqueado só no trecho final. Exige contexto
 * de equipe ou de sistema (o contexto de comprador só lê o contador).
 */
final class OrderNumbering
{
    public const string PROVISIONAL_PREFIX = 'PEND-';

    public static function provisional(): string
    {
        return self::PROVISIONAL_PREFIX.Str::upper(Str::random(15));
    }

    public function assign(Order $order): void
    {
        if (! str_starts_with($order->number, self::PROVISIONAL_PREFIX)) {
            return;
        }

        $row = DB::selectOne(
            'insert into order_number_counters (tenant_id, last_number, updated_at) values (?, 1, now())
             on conflict (tenant_id) do update set last_number = order_number_counters.last_number + 1, updated_at = now()
             returning last_number',
            [$order->tenant_id],
        );

        $order->forceFill(['number' => 'PED-'.str_pad((string) $row->last_number, 7, '0', STR_PAD_LEFT)])->save();
    }
}
