<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\Exceptions\InvalidPriceListTransition;
use App\Enums\Identity\Permission;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Pricing\PriceList;
use App\Services\Audit\AuditRecorder;
use App\Services\Pricing\PriceVersion;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Ativa uma tabela em rascunho: a partir daí ela participa da resolução de
 * preços dentro da vigência. Tabelas arquivadas não voltam a ficar ativas
 * (o histórico de documentos referencia a versão arquivada).
 */
final readonly class ActivatePriceList
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private PriceVersion $version,
        private Clock $clock,
    ) {}

    public function handle(PriceList $priceList): PriceList
    {
        $this->access->authorize(Permission::PricingManage);

        return DB::transaction(function () use ($priceList): PriceList {
            $list = PriceList::query()->lockForUpdate()->findOrFail($priceList->id);

            if ($list->status === PriceListStatus::Active) {
                return $list;
            }

            if ($list->status === PriceListStatus::Archived) {
                throw new InvalidPriceListTransition('Tabela arquivada não pode ser reativada. Cadastre uma nova tabela.');
            }

            if ($list->valid_until !== null && $list->valid_until->toDateString() < $this->clock->today()->toDateString()) {
                throw new InvalidPriceListTransition('A vigência desta tabela já terminou. Ajuste a vigência antes de ativá-la.');
            }

            $list->forceFill(['status' => PriceListStatus::Active])->save();

            $this->audit->record('price_list.activated', $list, [
                'code' => $list->code,
                'from' => PriceListStatus::Draft->value,
                'to' => PriceListStatus::Active->value,
            ]);
            $this->version->bump();

            return $list;
        });
    }
}
