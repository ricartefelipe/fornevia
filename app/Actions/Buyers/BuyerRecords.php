<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Domain\Buyers\Exceptions\BuyerUnitMismatch;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Support\Http\UntrustedId;

/**
 * Carregamento, no escopo do tenant ativo, dos registros referenciados
 * pelas Actions de compradores. Modelos recebidos de fora são sempre
 * recarregados: um registro de outro tenant equivale a inexistente.
 */
final readonly class BuyerRecords
{
    /**
     * Recarrega a empresa com bloqueio de linha. Serializa as alterações
     * que dependem do conjunto de registros da empresa (endereço padrão,
     * contato principal, condições de pagamento, situação).
     */
    public function lockCompany(BuyerCompany $company): BuyerCompany
    {
        return BuyerCompany::query()->lockForUpdate()->findOrFail($company->id);
    }

    public function company(BuyerCompany $company): BuyerCompany
    {
        return BuyerCompany::query()->findOrFail($company->id);
    }

    /**
     * Unidade opcional, que precisa pertencer à mesma empresa compradora.
     */
    public function unitOf(BuyerCompany $company, ?string $unitId): ?BuyerUnit
    {
        if ($unitId === null || trim($unitId) === '') {
            return null;
        }

        $unit = BuyerUnit::query()->findOrFail(UntrustedId::uuid($unitId, BuyerUnit::class));

        if ($unit->buyer_company_id !== $company->id) {
            throw new BuyerUnitMismatch('A unidade informada não pertence a esta empresa compradora.', ['field' => 'buyer_unit_id']);
        }

        return $unit;
    }
}
