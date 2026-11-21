<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Nomes estáveis de tipos polimórficos persistidos (auditoria, anexos,
 * referências de movimentos). Alterar um nome exige migration de dados.
 */
final class MorphMap
{
    /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    public const array MAP = [
        'user' => Identity\User::class,
        'organization' => Identity\Organization::class,
        'branch' => Identity\Branch::class,
        'role' => Identity\Role::class,
        'membership' => Identity\Membership::class,
        'invitation' => Identity\Invitation::class,
        'customer_group' => Buyers\CustomerGroup::class,
        'buyer_company' => Buyers\BuyerCompany::class,
        'buyer_unit' => Buyers\BuyerUnit::class,
        'buyer_address' => Buyers\BuyerAddress::class,
        'cost_center' => Buyers\CostCenter::class,
        'buyer_contact' => Buyers\BuyerContact::class,
        'unit_of_measure' => Catalog\UnitOfMeasure::class,
        'category' => Catalog\Category::class,
        'product' => Catalog\Product::class,
        'sku' => Catalog\Sku::class,
        'product_media' => Catalog\ProductMedia::class,
        'private_catalog' => Catalog\PrivateCatalog::class,
        'payment_term' => Pricing\PaymentTerm::class,
        'price_list' => Pricing\PriceList::class,
        'price_list_item' => Pricing\PriceListItem::class,
        'negotiated_price' => Pricing\NegotiatedPrice::class,
        'warehouse' => Inventory\Warehouse::class,
        'credit_account' => Credit\CreditAccount::class,
        'credit_exception' => Credit\CreditException::class,
        'cart' => Ordering\Cart::class,
        'shopping_list' => Ordering\ShoppingList::class,
        'order' => Ordering\Order::class,
        'order_item' => Ordering\OrderItem::class,
        'approval_rule' => Approvals\ApprovalRule::class,
        'approval_delegation' => Approvals\ApprovalDelegation::class,
        'cost_center_budget' => Approvals\CostCenterBudget::class,
        'plan' => Platform\Plan::class,
        'subscription' => Platform\Subscription::class,
    ];
}
