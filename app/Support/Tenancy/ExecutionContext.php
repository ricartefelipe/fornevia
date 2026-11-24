<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\InvalidExecutionContext;

/**
 * Identidade e escopo de uma unidade de trabalho (requisição, job, comando).
 *
 * Imutável. Construído somente a partir de dados já validados contra a
 * identidade autenticada (associação ativa, token de integração ou job
 * interno) e nunca diretamente de entrada do cliente.
 */
final readonly class ExecutionContext
{
    private function __construct(
        public ContextKind $kind,
        public ?string $userId,
        public ?string $tenantId,
        public ?string $buyerCompanyId,
        public ?string $membershipId,
        public ?string $apiClientId,
    ) {
        if ($kind->hasTenant() && $tenantId === null) {
            throw new InvalidExecutionContext("O contexto {$kind->value} exige tenant.");
        }

        if ($kind === ContextKind::Buyer && $buyerCompanyId === null) {
            throw new InvalidExecutionContext('O contexto de comprador exige empresa compradora.');
        }

        if ($kind !== ContextKind::Buyer && $buyerCompanyId !== null) {
            throw new InvalidExecutionContext('Somente o contexto de comprador restringe a empresa compradora.');
        }

        foreach ([$userId, $tenantId, $buyerCompanyId, $membershipId, $apiClientId] as $id) {
            if ($id !== null && ! self::isUuid($id)) {
                throw new InvalidExecutionContext('Identificador de contexto inválido.');
            }
        }
    }

    public static function user(string $userId): self
    {
        return new self(ContextKind::User, $userId, null, null, null, null);
    }

    public static function staff(string $userId, string $tenantId, string $membershipId): self
    {
        return new self(ContextKind::Staff, $userId, $tenantId, null, $membershipId, null);
    }

    public static function buyer(string $userId, string $tenantId, string $buyerCompanyId, string $membershipId): self
    {
        return new self(ContextKind::Buyer, $userId, $tenantId, $buyerCompanyId, $membershipId, null);
    }

    public static function integration(string $tenantId, string $apiClientId): self
    {
        return new self(ContextKind::Integration, null, $tenantId, null, null, $apiClientId);
    }

    /**
     * Contexto de sistema do tenant; quando elevado a partir de um usuário ou
     * de um cliente da API, guarda quem age (auditoria).
     */
    public static function system(string $tenantId, ?string $actingUserId = null, ?string $actingApiClientId = null): self
    {
        return new self(ContextKind::System, $actingUserId, $tenantId, null, null, $actingApiClientId);
    }

    public function requireTenantId(): string
    {
        return $this->tenantId ?? throw new InvalidExecutionContext('Operação exige tenant no contexto.');
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->userId === $other->userId
            && $this->tenantId === $other->tenantId
            && $this->buyerCompanyId === $other->buyerCompanyId
            && $this->membershipId === $other->membershipId
            && $this->apiClientId === $other->apiClientId;
    }

    /**
     * Valores aplicados com set_config(..., is_local = true).
     *
     * @return array<string, string>
     */
    public function databaseSettings(): array
    {
        return [
            'app.context_kind' => $this->kind->value,
            'app.user_id' => $this->userId ?? '',
            'app.tenant_id' => $this->tenantId ?? '',
            'app.buyer_company_id' => $this->buyerCompanyId ?? '',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function toLogContext(): array
    {
        return array_filter([
            'context_kind' => $this->kind->value,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'buyer_company_id' => $this->buyerCompanyId,
            'api_client_id' => $this->apiClientId,
        ], static fn (?string $value): bool => $value !== null);
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
