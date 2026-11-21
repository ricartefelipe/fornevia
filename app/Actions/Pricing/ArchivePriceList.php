<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Enums\Identity\Permission;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Pricing\PriceList;
use App\Services\Audit\AuditRecorder;
use App\Services\Pricing\PriceVersion;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Arquiva uma tabela (rascunho ou ativa). A tabela deixa de participar da
 * resolução de preços imediatamente e não aceita mais alterações; o
 * registro é mantido para rastreabilidade.
 */
final readonly class ArchivePriceList
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private PriceVersion $version,
    ) {}

    public function handle(PriceList $priceList, ?string $reason = null): PriceList
    {
        $this->access->authorize(Permission::PricingManage);

        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        return DB::transaction(function () use ($priceList, $reason): PriceList {
            $list = PriceList::query()->lockForUpdate()->findOrFail($priceList->id);

            if ($list->status === PriceListStatus::Archived) {
                return $list;
            }

            $from = $list->status;
            $list->forceFill(['status' => PriceListStatus::Archived])->save();

            $this->audit->record('price_list.archived', $list, [
                'code' => $list->code,
                'from' => $from->value,
                'to' => PriceListStatus::Archived->value,
            ], $reason);
            $this->version->bump();

            return $list;
        });
    }
}
