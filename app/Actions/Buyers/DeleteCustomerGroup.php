<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Domain\Buyers\Exceptions\CustomerGroupInUse;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Models\Catalog\PrivateCatalogAssignment;
use App\Models\Pricing\PriceList;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Exclui um grupo de clientes sem uso. Grupos com empresas, tabelas de
 * preço ou catálogos privados vinculados não são excluídos: a exclusão
 * mudaria preço e visibilidade de catálogo de forma implícita (as
 * atribuições de catálogo seriam removidas em cascata).
 *
 * O bloqueio da linha do grupo conflita com o FOR KEY SHARE das chaves
 * estrangeiras: um vínculo criado em paralelo espera esta transação.
 */
final readonly class DeleteCustomerGroup
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(CustomerGroup $group): void
    {
        $this->access->authorize(Permission::BuyersManage);

        DB::transaction(function () use ($group): void {
            $locked = CustomerGroup::query()->lockForUpdate()->findOrFail($group->id);

            $usage = [
                'buyer_companies' => BuyerCompany::query()->where('customer_group_id', $locked->id)->count(),
                'price_lists' => PriceList::query()->where('customer_group_id', $locked->id)->count(),
                'private_catalogs' => PrivateCatalogAssignment::query()->where('customer_group_id', $locked->id)->count(),
            ];

            if (array_sum($usage) > 0) {
                throw new CustomerGroupInUse(
                    'O grupo possui empresas, tabelas de preço ou catálogos privados vinculados e não pode ser excluído.',
                    ['field' => 'code', 'usage' => $usage],
                );
            }

            $snapshot = $locked->only(['code', 'name', 'description']);
            $locked->delete();

            $this->audit->record('customer_group.deleted', $locked, ['before' => $snapshot]);
        });
    }
}
