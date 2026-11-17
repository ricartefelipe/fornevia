<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\Permission;
use App\Models\Identity\Membership;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Permissões efetivas da unidade de trabalho corrente.
 *
 * Preenchido pelo middleware de contexto com a associação validada no
 * banco (ou pela autenticação de integração). Registrado como scoped:
 * nunca sobrevive entre requisições ou jobs.
 */
final class Access
{
    private ?Membership $membership = null;

    /** @var list<string>|null */
    private ?array $integrationPermissions = null;

    private ?string $apiClientId = null;

    public function __construct(private readonly CurrentContext $context) {}

    /**
     * @internal Middleware de contexto.
     */
    public function bindMembership(Membership $membership): void
    {
        $membership->loadMissing('role');
        $this->membership = $membership;
        $this->integrationPermissions = null;
        $this->apiClientId = null;
    }

    /**
     * @internal Autenticação de integrações.
     *
     * @param  list<string>  $permissions
     */
    public function bindIntegration(string $apiClientId, array $permissions): void
    {
        $this->membership = null;
        $this->apiClientId = $apiClientId;
        $this->integrationPermissions = $permissions;
    }

    public function hasMembership(): bool
    {
        return $this->membership !== null;
    }

    public function membership(): Membership
    {
        return $this->membership ?? throw new AuthorizationException('Nenhum contexto ativo.');
    }

    public function membershipOrNull(): ?Membership
    {
        return $this->membership;
    }

    public function apiClientId(): ?string
    {
        return $this->apiClientId;
    }

    public function isStaff(): bool
    {
        return $this->membership?->kind === MembershipKind::Staff;
    }

    public function isBuyer(): bool
    {
        return $this->membership?->kind === MembershipKind::Buyer;
    }

    public function isIntegration(): bool
    {
        return $this->apiClientId !== null;
    }

    public function buyerCompanyId(): ?string
    {
        return $this->membership?->buyer_company_id;
    }

    public function userId(): ?string
    {
        return $this->membership?->user_id;
    }

    public function can(Permission $permission): bool
    {
        $active = $this->context->get();

        if ($this->integrationPermissions !== null) {
            return $active?->apiClientId === $this->apiClientId
                && in_array($permission->value, $this->integrationPermissions, true);
        }

        $membership = $this->membership;

        // Permissões só valem dentro do contexto da própria associação (não,
        // por exemplo, durante uma elevação para outro contexto).
        if ($membership === null || ! $membership->isActive() || $active?->membershipId !== $membership->id) {
            return false;
        }

        // Permissões de portal só valem em contexto de comprador e vice-versa.
        if ($permission->isBuyerPermission() !== ($membership->kind === MembershipKind::Buyer)) {
            return false;
        }

        return $membership->role->grants($permission);
    }

    public function authorize(Permission $permission): void
    {
        if (! $this->can($permission)) {
            throw new AuthorizationException('Permissão necessária: '.$permission->label().'.');
        }
    }

    public function maxDiscountBps(): int
    {
        return $this->membership?->role->max_discount_bps ?? 0;
    }
}
