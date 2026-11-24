<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Enums\Identity\Permission;

/**
 * Permissões que um cliente de API pode receber (RF-110): leitura dos
 * cadastros, pedidos, estoque e financeiro, e registro de eventos de
 * transportadora. Nenhuma permissão de portal, equipe ou assinatura.
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
