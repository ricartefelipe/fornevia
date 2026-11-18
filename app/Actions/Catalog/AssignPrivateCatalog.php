<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Data\Catalog\PrivateCatalogTarget;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Models\Catalog\PrivateCatalog;
use App\Models\Catalog\PrivateCatalogAssignment;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Support\Facades\DB;

/**
 * Atribui um catálogo privado a uma empresa compradora ou a um grupo de
 * clientes do mesmo tenant. Atribuir novamente o mesmo destino não duplica
 * o registro. Atribuições do mesmo catálogo são serializadas pelo lock do
 * catálogo.
 */
final readonly class AssignPrivateCatalog
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(PrivateCatalog $catalog, PrivateCatalogTarget $target): PrivateCatalogAssignment
    {
        $this->access->authorize(Permission::CatalogManage);

        return DB::transaction(function () use ($catalog, $target): PrivateCatalogAssignment {
            $locked = PrivateCatalog::query()->lockForUpdate()->findOrFail($catalog->id);

            if ($target->isBuyerCompany()) {
                $company = BuyerCompany::query()->findOrFail(UntrustedId::uuid($target->buyerCompanyId, BuyerCompany::class));
                $column = 'buyer_company_id';
                $targetId = $company->id;
                $targetName = $company->trade_name;
                $targetType = 'buyer_company';
            } else {
                $group = CustomerGroup::query()->findOrFail(UntrustedId::uuid($target->customerGroupId, CustomerGroup::class));
                $column = 'customer_group_id';
                $targetId = $group->id;
                $targetName = $group->name;
                $targetType = 'customer_group';
            }

            $existing = PrivateCatalogAssignment::query()
                ->where('private_catalog_id', $locked->id)
                ->where($column, $targetId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $assignment = new PrivateCatalogAssignment;
            $assignment->forceFill(['private_catalog_id' => $locked->id, $column => $targetId])->save();

            $this->audit->record('private_catalog.assigned', $locked, [
                'assignment_id' => $assignment->id,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'target_name' => $targetName,
            ]);

            return $assignment;
        });
    }
}
