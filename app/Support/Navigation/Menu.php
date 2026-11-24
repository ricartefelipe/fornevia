<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Enums\Identity\Permission;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\Route;

/**
 * Itens de navegação visíveis no contexto atual. Ocultar um item não é
 * controle de acesso: cada rota e ação valida a permissão no servidor.
 */
final readonly class Menu
{
    /**
     * Rota => [rótulo, permissão exigida (null = qualquer membro do contexto)].
     *
     * @var array<string, array{0: string, 1: Permission|null}>
     */
    private const array STAFF = [
        'dashboard' => ['Painel', null],
        'buyers.index' => ['Empresas compradoras', Permission::BuyersView],
        'catalog.products' => ['Catálogo', Permission::CatalogView],
        'pricing.lists' => ['Tabelas de preço', Permission::PricingView],
        'quotes.index' => ['Cotações', Permission::QuotesView],
        'orders.index' => ['Pedidos', Permission::OrdersView],
        'inventory.index' => ['Estoque', Permission::InventoryView],
        'credit.index' => ['Crédito comercial', Permission::CreditView],
        'fulfillment.index' => ['Expedição', Permission::FulfillmentView],
        'returns.index' => ['Devoluções', Permission::ReturnsView],
        'finance.index' => ['Financeiro', Permission::FinanceView],
        'reports.indicators' => ['Indicadores', Permission::ReportsView],
        'integrations.index' => ['Integrações', Permission::IntegrationsManage],
        'audit.index' => ['Auditoria', Permission::AuditView],
        'team.index' => ['Equipe e permissões', Permission::MembersManage],
        'branches.index' => ['Filiais', Permission::OrganizationManage],
        'subscription.show' => ['Assinatura', Permission::SubscriptionManage],
    ];

    /** @var array<string, array{0: string, 1: Permission|null}> */
    private const array BUYER = [
        'portal.home' => ['Início', null],
        'portal.catalog' => ['Catálogo', Permission::PortalCatalog],
        'portal.cart' => ['Carrinho', Permission::PortalCart],
        'portal.quick-order' => ['Pedido rápido', Permission::PortalCart],
        'portal.lists' => ['Listas de compra', Permission::PortalCart],
        'portal.quotes' => ['Cotações', Permission::PortalQuotes],
        'portal.approvals' => ['Aprovações', Permission::PortalApprovals],
        'portal.approval-rules' => ['Regras de aprovação', Permission::PortalApprovalRules],
        'portal.delegations' => ['Delegações', Permission::PortalApprovals],
        'portal.orders' => ['Pedidos', null],
        'portal.returns' => ['Devoluções', null],
        'portal.invoices' => ['Faturas', null],
        'portal.budgets' => ['Orçamentos', Permission::PortalBudgets],
        'portal.users' => ['Usuários da empresa', Permission::PortalUsers],
    ];

    public function __construct(private Access $access) {}

    /**
     * Padrão de rota que marca o item como ativo: o módulo inteiro na equipe
     * ("inventory.*" destaca Estoque em todas as subtelas) e a própria
     * seção no portal, cujas rotas compartilham o prefixo "portal.".
     */
    public static function activePattern(string $route): string
    {
        if (str_starts_with($route, 'portal.') || ! str_contains($route, '.')) {
            return $route.'*';
        }

        return strstr($route, '.', true).'.*';
    }

    /**
     * @return list<array{route: string, label: string}>
     */
    public function items(): array
    {
        $definitions = match (true) {
            $this->access->isStaff() => self::STAFF,
            $this->access->isBuyer() => self::BUYER,
            default => [],
        };

        $items = [];

        foreach ($definitions as $route => [$label, $permission]) {
            if (! Route::has($route) || ($permission !== null && ! $this->access->can($permission))) {
                continue;
            }

            $items[] = ['route' => $route, 'label' => $label];
        }

        return $items;
    }
}
