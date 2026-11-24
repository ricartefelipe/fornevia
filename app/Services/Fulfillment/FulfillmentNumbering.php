<?php

declare(strict_types=1);

namespace App\Services\Fulfillment;

use Illuminate\Support\Facades\DB;

/**
 * Números de remessa (EXP-0000001) e de devolução (DEV-0000001) por
 * distribuidor, sem lacunas na transação que cria o documento. O contador
 * é o último lock da transação (ADR-006).
 */
final class FulfillmentNumbering
{
    public function next(string $tenantId, string $kind): string
    {
        $row = DB::selectOne(
            'insert into fulfillment_number_counters (tenant_id, kind, last_number, updated_at) values (?, ?, 1, now())
             on conflict (tenant_id, kind) do update set last_number = fulfillment_number_counters.last_number + 1, updated_at = now()
             returning last_number',
            [$tenantId, $kind],
        );

        return ($kind === 'shipment' ? 'EXP-' : 'DEV-').str_pad((string) $row->last_number, 7, '0', STR_PAD_LEFT);
    }
}
