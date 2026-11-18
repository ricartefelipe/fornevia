<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Enums\Identity\Permission;
use App\Models\Catalog\PrivateCatalog;
use App\Models\Catalog\PrivateCatalogAssignment;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Remove a atribuição de um catálogo privado. Os produtos restritos do
 * catálogo deixam de ser visíveis ao destino na requisição seguinte.
 */
final readonly class UnassignPrivateCatalog
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(PrivateCatalogAssignment $assignment): void
    {
        $this->access->authorize(Permission::CatalogManage);

        DB::transaction(function () use ($assignment): void {
            $locked = PrivateCatalogAssignment::query()->findOrFail($assignment->id);
            $catalog = PrivateCatalog::query()->lockForUpdate()->findOrFail($locked->private_catalog_id);
            $locked = PrivateCatalogAssignment::query()->lockForUpdate()->findOrFail($locked->id);

            $locked->delete();

            $this->audit->record('private_catalog.unassigned', $catalog, [
                'assignment_id' => $locked->id,
                'target_type' => $locked->buyer_company_id !== null ? 'buyer_company' : 'customer_group',
                'target_id' => $locked->buyer_company_id ?? $locked->customer_group_id,
            ]);
        });
    }
}
