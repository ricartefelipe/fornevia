<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal\Support;

use App\Data\Ordering\Portal\BuyerActor;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Support\Auth\Access;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Comprador do contexto atual para as operações de carrinho, listas e
 * pedidos do portal. Exige a associação de comprador vinculada ao contexto
 * da transação (a mesma empresa da sessão) e, quando informada, a
 * permissão da operação.
 */
final readonly class PortalBuyer
{
    public function __construct(
        private Access $access,
        private CurrentContext $context,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function require(?Permission $permission = null): BuyerActor
    {
        if ($permission !== null) {
            $this->access->authorize($permission);
        }

        $membership = $this->access->membershipOrNull();
        $companyId = $membership?->buyer_company_id;
        $active = $this->context->get();

        if ($membership === null || ! $this->access->isBuyer() || ! $membership->isActive() || $companyId === null
            || $active?->membershipId !== $membership->id || $active->buyerCompanyId !== $companyId) {
            throw new AuthorizationException('Esta operação exige o contexto da empresa compradora.');
        }

        return new BuyerActor($membership, BuyerCompany::query()->whereKey($companyId)->firstOrFail());
    }

    public function can(Permission $permission): bool
    {
        return $this->access->can($permission);
    }
}
