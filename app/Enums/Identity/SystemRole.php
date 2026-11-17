<?php

declare(strict_types=1);

namespace App\Enums\Identity;

use App\Enums\Identity\Permission as P;

/**
 * Papéis criados para cada novo tenant. Podem ter as permissões ajustadas
 * pelo tenant, exceto o papel de proprietário.
 */
enum SystemRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case SalesManager = 'sales_manager';
    case SalesRep = 'sales_rep';
    case Warehouse = 'warehouse';
    case Finance = 'finance';
    case Viewer = 'viewer';
    case BuyerAdmin = 'buyer_admin';
    case Purchaser = 'purchaser';
    case Requester = 'requester';
    case Approver = 'approver';

    public function kind(): MembershipKind
    {
        return match ($this) {
            self::BuyerAdmin, self::Purchaser, self::Requester, self::Approver => MembershipKind::Buyer,
            default => MembershipKind::Staff,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Proprietário',
            self::Admin => 'Administrador',
            self::SalesManager => 'Gerente comercial',
            self::SalesRep => 'Representante comercial',
            self::Warehouse => 'Estoque e expedição',
            self::Finance => 'Financeiro e crédito',
            self::Viewer => 'Consulta',
            self::BuyerAdmin => 'Administrador da empresa',
            self::Purchaser => 'Comprador',
            self::Requester => 'Solicitante',
            self::Approver => 'Aprovador',
        };
    }

    /**
     * Limite de desconto concedido pelo papel, em pontos-base.
     */
    public function maxDiscountBps(): int
    {
        return match ($this) {
            self::Owner, self::Admin => 10_000,
            self::SalesManager => 1_500,
            self::SalesRep => 500,
            default => 0,
        };
    }

    /**
     * @return list<P>
     */
    public function permissions(): array
    {
        $staffAll = P::forKind(MembershipKind::Staff);
        $views = [P::BuyersView, P::CatalogView, P::PricingView, P::QuotesView, P::OrdersView, P::InventoryView,
            P::CreditView, P::FulfillmentView, P::ReturnsView, P::FinanceView, P::ReportsView];

        return match ($this) {
            self::Owner => $staffAll,
            self::Admin => array_values(array_filter($staffAll, static fn (P $p): bool => $p !== P::SubscriptionManage)),
            self::SalesManager => [P::BuyersView, P::BuyersManage, P::CatalogView, P::CatalogViewCosts, P::PricingView,
                P::PricingManage, P::PricingApprove, P::QuotesView, P::QuotesManage, P::OrdersView, P::OrdersManage,
                P::InventoryView, P::CreditView, P::FulfillmentView, P::ReturnsView, P::ReportsView, P::AuditView],
            self::SalesRep => [P::BuyersView, P::CatalogView, P::PricingView, P::QuotesView, P::QuotesManage,
                P::OrdersView, P::InventoryView, P::CreditView],
            self::Warehouse => [P::CatalogView, P::OrdersView, P::InventoryView, P::InventoryManage,
                P::FulfillmentView, P::FulfillmentManage, P::ReturnsView, P::ReturnsManage],
            self::Finance => [P::BuyersView, P::OrdersView, P::CreditView, P::CreditManage, P::FinanceView,
                P::FinanceManage, P::ReturnsView, P::ReportsView, P::AuditView],
            self::Viewer => $views,
            self::BuyerAdmin => P::forKind(MembershipKind::Buyer),
            self::Purchaser => [P::PortalCatalog, P::PortalCart, P::PortalOrdersPlace, P::PortalOrdersViewAll, P::PortalQuotes],
            self::Requester => [P::PortalCatalog, P::PortalCart, P::PortalQuotes],
            self::Approver => [P::PortalCatalog, P::PortalApprovals, P::PortalOrdersViewAll],
        };
    }

    /**
     * @return list<self>
     */
    public static function forKind(MembershipKind $kind): array
    {
        return array_values(array_filter(self::cases(), static fn (self $r): bool => $r->kind() === $kind));
    }
}
