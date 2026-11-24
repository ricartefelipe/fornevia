<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Enums\Identity\Permission;

/**
 * Permissões que um cliente de API pode receber (RF-110): leitura dos
 * cadastros, pedidos, estoque e financeiro; registro de eventos de
 * transportadora; cadastro de empresas compradoras, entrada e ajuste de
 * estoque e cancelamento de pedidos. Nenhuma permissão de portal, de
 * gestão da equipe, de preços, de crédito ou de assinatura.
 */
final class ApiAbilities
{
    /** @var list<Permission> */
    public const array ALLOWED = [
        Permission::OrdersView,
        Permission::BuyersView,
        Permission::CatalogView,
        Permission::InventoryView,
        Permission::FinanceView,
        Permission::FulfillmentManage,
        Permission::BuyersManage,
        Permission::InventoryManage,
        Permission::OrdersManage,
    ];

    /**
     * @return array<string, string> valor => rótulo
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::ALLOWED as $permission) {
            $options[$permission->value] = $permission->label();
        }

        return $options;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function filter(array $values): array
    {
        $allowed = array_map(static fn (Permission $p): string => $p->value, self::ALLOWED);

        return array_values(array_unique(array_intersect($values, $allowed)));
    }
}
