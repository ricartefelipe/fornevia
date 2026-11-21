<?php

declare(strict_types=1);

namespace App\Enums\Identity;

/**
 * Catálogo de permissões. Cada papel (por tenant) concede um subconjunto.
 * Permissões "portal.*" pertencem ao contexto de comprador; as demais, à
 * equipe do distribuidor.
 */
enum Permission: string
{
    // Equipe do distribuidor
    case OrganizationManage = 'organization.manage';
    case MembersManage = 'members.manage';
    case BuyersView = 'buyers.view';
    case BuyersManage = 'buyers.manage';
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';
    case CatalogViewCosts = 'catalog.view_costs';
    case PricingView = 'pricing.view';
    case PricingManage = 'pricing.manage';
    case PricingApprove = 'pricing.approve';
    case QuotesView = 'quotes.view';
    case QuotesManage = 'quotes.manage';
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case InventoryView = 'inventory.view';
    case InventoryManage = 'inventory.manage';
    case CreditView = 'credit.view';
    case CreditManage = 'credit.manage';
    case FulfillmentView = 'fulfillment.view';
    case FulfillmentManage = 'fulfillment.manage';
    case ReturnsView = 'returns.view';
    case ReturnsManage = 'returns.manage';
    case FinanceView = 'finance.view';
    case FinanceManage = 'finance.manage';
    case IntegrationsManage = 'integrations.manage';
    case AuditView = 'audit.view';
    case ReportsView = 'reports.view';
    case SubscriptionManage = 'subscription.manage';

    // Empresa compradora (portal)
    case PortalCatalog = 'portal.catalog';
    case PortalCart = 'portal.cart';
    case PortalOrdersPlace = 'portal.orders.place';
    case PortalOrdersViewAll = 'portal.orders.view_all';
    case PortalQuotes = 'portal.quotes';
    case PortalApprovals = 'portal.approvals';
    case PortalBudgets = 'portal.budgets';
    case PortalUsers = 'portal.users';

    public function isBuyerPermission(): bool
    {
        return str_starts_with($this->value, 'portal.');
    }

    /**
     * Permissões que exigem segundo fator confirmado.
     */
    public function isPrivileged(): bool
    {
        return in_array($this, [
            self::OrganizationManage,
            self::MembersManage,
            self::CreditManage,
            self::FinanceManage,
            self::IntegrationsManage,
            self::SubscriptionManage,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function forKind(MembershipKind $kind): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $p): bool => $p->isBuyerPermission() === ($kind === MembershipKind::Buyer),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::OrganizationManage => 'Gerenciar organização e filiais',
            self::MembersManage => 'Gerenciar equipe e convites',
            self::BuyersView => 'Ver empresas compradoras',
            self::BuyersManage => 'Gerenciar empresas compradoras',
            self::CatalogView => 'Ver catálogo',
            self::CatalogManage => 'Gerenciar catálogo',
            self::CatalogViewCosts => 'Ver custos e margens',
            self::PricingView => 'Ver tabelas de preço',
            self::PricingManage => 'Gerenciar tabelas de preço',
            self::PricingApprove => 'Aprovar descontos e exceções de preço',
            self::QuotesView => 'Ver cotações',
            self::QuotesManage => 'Elaborar propostas',
            self::OrdersView => 'Ver pedidos',
            self::OrdersManage => 'Gerenciar pedidos',
            self::InventoryView => 'Ver estoque',
            self::InventoryManage => 'Movimentar estoque',
            self::CreditView => 'Ver crédito comercial',
            self::CreditManage => 'Gerenciar limites e exceções de crédito',
            self::FulfillmentView => 'Ver expedição',
            self::FulfillmentManage => 'Operar separação e expedição',
            self::ReturnsView => 'Ver devoluções',
            self::ReturnsManage => 'Operar devoluções',
            self::FinanceView => 'Ver financeiro',
            self::FinanceManage => 'Operar financeiro',
            self::IntegrationsManage => 'Gerenciar integrações',
            self::AuditView => 'Ver auditoria',
            self::ReportsView => 'Ver indicadores',
            self::SubscriptionManage => 'Gerenciar assinatura do Fornevia',
            self::PortalCatalog => 'Ver catálogo e preços',
            self::PortalCart => 'Montar carrinho e listas',
            self::PortalOrdersPlace => 'Confirmar pedidos',
            self::PortalOrdersViewAll => 'Ver todos os pedidos da empresa',
            self::PortalQuotes => 'Solicitar cotações',
            self::PortalApprovals => 'Aprovar compras',
            self::PortalBudgets => 'Gerenciar orçamentos',
            self::PortalUsers => 'Gerenciar usuários da empresa',
        };
    }
}
