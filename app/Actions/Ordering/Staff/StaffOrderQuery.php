<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Staff;

use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Models\Ordering\Order;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pedidos visíveis à equipe do distribuidor no contexto atual.
 *
 * - Exige orders.view, que só tem efeito em contexto de equipe.
 * - Isolamento entre distribuidores: TenantScope e RLS tenant_isolation.
 * - Carteira: o representante comercial vê apenas pedidos das empresas pelas
 *   quais responde hoje (buyer_companies.sales_rep_membership_id). A RLS não
 *   cobre esse recorte (app_sees_all_buyers() é verdadeiro para toda a
 *   equipe), por isso ele é aplicado aqui, em toda consulta de pedidos da
 *   equipe. O representante gravado no pedido
 *   (orders.sales_rep_membership_id) é registro histórico da confirmação e
 *   não define visibilidade: ao transferir a empresa de carteira, os pedidos
 *   acompanham a empresa.
 */
final readonly class StaffOrderQuery
{
    public function __construct(private Access $access) {}

    /**
     * @return Builder<Order>
     */
    public function orders(): Builder
    {
        $portfolio = $this->portfolioMembershipId();
        $query = Order::query();

        if ($portfolio !== null) {
            $query->whereIn('buyer_company_id', BuyerCompany::query()->select('id')->where('sales_rep_membership_id', $portfolio));
        }

        return $query;
    }

    /**
     * Pedido pelo identificador recebido do cliente: fora do formato UUID,
     * de outro distribuidor ou fora da carteira equivale a inexistente.
     */
    public function find(mixed $orderId): Order
    {
        return $this->orders()->findOrFail(UntrustedId::uuid($orderId, Order::class));
    }

    /**
     * Empresas cujos pedidos o contexto atual pode consultar.
     *
     * @return Builder<BuyerCompany>
     */
    public function companies(): Builder
    {
        $portfolio = $this->portfolioMembershipId();
        $query = BuyerCompany::query();

        if ($portfolio !== null) {
            $query->where('sales_rep_membership_id', $portfolio);
        }

        return $query;
    }

    /**
     * Associação cuja carteira limita a consulta, ou null quando o papel vê
     * os pedidos de todas as empresas do distribuidor.
     */
    public function portfolioMembershipId(): ?string
    {
        $this->access->authorize(Permission::OrdersView);
        $membership = $this->access->membership();

        return $membership->role->key === SystemRole::SalesRep->value ? $membership->id : null;
    }
}
