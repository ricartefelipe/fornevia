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
        'plan' => Platform\Plan::class,
        'subscription' => Platform\Subscription::class,
    ];
}
