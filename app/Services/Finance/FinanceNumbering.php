<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

/**
 * Número da fatura por distribuidor (FAT-0000001), sem lacunas na
 * transação que a emite; o contador é o último lock (ADR-006).
 */
final class FinanceNumbering
{
    public function next(string $tenantId): string
    {
        $row = DB::selectOne(
            'insert into finance_number_counters (tenant_id, last_number, updated_at) values (?, 1, now())
             on conflict (tenant_id) do update set last_number = finance_number_counters.last_number + 1, updated_at = now()
             returning last_number',
            [$tenantId],
        );

        return 'FAT-'.str_pad((string) $row->last_number, 7, '0', STR_PAD_LEFT);
    }
}
