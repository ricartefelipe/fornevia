<?php

declare(strict_types=1);

namespace Tests\Support\Builders;

use App\Actions\Identity\CreateOrganization;
use App\Data\Identity\CreateOrganizationData;
use App\Domain\Shared\Cnpj;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use App\Models\Identity\Organization;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Support\Auth\Access;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Database\Factories\Support\BrazilianDocuments;
use Database\Seeders\PlanSeeder;

/**
 * Monta um distribuidor (tenant) pelos mesmos caminhos da aplicação:
 * onboarding via CreateOrganization e associações com os papéis padrão.
 * Todos os dados ficam commitados (sem transação envolvendo o teste).
 */
final class TenantScenario
{
    private function __construct(
        public readonly Organization $organization,
        public readonly User $owner,
        public readonly Membership $ownerMembership,
    ) {}

    public static function create(string $plan = 'profissional', ?string $name = null): self
    {
        if (! \App\Models\Platform\Plan::query()->exists()) {
            (new PlanSeeder)->run();
        }

        $owner = User::factory()->create();
        $tradeName = $name ?? 'Distribuidora '.fake()->unique()->lastName();

        $membership = app(CreateOrganization::class)->handle($owner, new CreateOrganizationData(
            legalName: $tradeName.' Ltda',
            tradeName: $tradeName,
            document: Cnpj::fromString(BrazilianDocuments::cnpj()),
            planCode: $plan,
        ));

        return new self(Organization::query()->findOrFail($membership->tenant_id), $owner, $membership);
    }

    public function role(SystemRole $role): Role
    {
        return $this->system(fn (): Role => Role::query()->where('key', $role->value)->firstOrFail());
    }

    public function addStaff(SystemRole $role, ?User $user = null): Membership
    {
        $user ??= User::factory()->create();

        return $this->system(function () use ($role, $user): Membership {
            $membership = new Membership;
            $membership->forceFill([
                'user_id' => $user->id,
                'kind' => MembershipKind::Staff,
                'role_id' => Role::query()->where('key', $role->value)->value('id'),
                'status' => MembershipStatus::Active,
            ])->save();

            return $membership;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addBuyerCompany(array $attributes = []): BuyerCompany
    {
        return $this->system(function () use ($attributes): BuyerCompany {
            $company = new BuyerCompany;
            $company->forceFill([
                'legal_name' => 'Compradora '.fake()->unique()->lastName().' S.A.',
                'trade_name' => 'Compradora '.fake()->lastName(),
                'document' => BrazilianDocuments::cnpj(),
                ...$attributes,
            ])->save();

            return $company;
        });
    }

    public function addBuyerUser(BuyerCompany $company, SystemRole $role = SystemRole::Purchaser, ?User $user = null): Membership
    {
        $user ??= User::factory()->create();

        return $this->system(function () use ($company, $role, $user): Membership {
            $membership = new Membership;
            $membership->forceFill([
                'user_id' => $user->id,
                'kind' => MembershipKind::Buyer,
                'buyer_company_id' => $company->id,
                'role_id' => Role::query()->where('key', $role->value)->value('id'),
                'status' => MembershipStatus::Active,
            ])->save();

            return $membership;
        });
    }

    /**
     * Executa no contexto de sistema do tenant (sem associação).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function system(Closure $callback): mixed
    {
        return app(TenantDatabase::class)->run(ExecutionContext::system($this->organization->id), $callback);
    }

    /**
     * Executa como a associação informada, com Access preenchido como faria
     * o middleware de contexto.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function actingAs(Membership $membership, Closure $callback): mixed
    {
        return app(TenantDatabase::class)->run($membership->executionContext(), static function () use ($membership, $callback): mixed {
            $access = app(Access::class);
            $access->bindMembership(Membership::query()->with('role')->findOrFail($membership->id));

            return $callback();
        });
    }

    /**
     * Sessão web autenticada já no contexto da associação.
     *
     * @return array<string, mixed>
     */
    public static function sessionFor(Membership $membership): array
    {
        return [\App\Http\Middleware\EstablishRequestContext::SESSION_KEY => \App\Http\Middleware\EstablishRequestContext::sessionPayload($membership)];
    }
}
