<?php

declare(strict_types=1);

namespace Tests\Integration\Buyers;

use App\Data\Buyers\BuyerAddressData;
use App\Data\Buyers\BuyerCompanyData;
use App\Domain\Shared\Cnpj;
use App\Enums\Buyers\AddressKind;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CustomerGroup;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Pricing\PaymentTerm;
use Closure;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Builders\TenantScenario;

/**
 * Apoio comum aos testes do cadastro de compradores: registros criados no
 * contexto de sistema do tenant, execução como uma associação (Actions e
 * componentes Livewire) e leituras de auditoria e versão de preços.
 */
final class BuyerFixtures
{
    /**
     * Executa como a associação, com o usuário autenticado no Livewire.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function livewireAs(TenantScenario $tenant, Membership $membership, Closure $callback): mixed
    {
        return $tenant->actingAs($membership, static function () use ($membership, $callback): mixed {
            Livewire::actingAs(User::query()->findOrFail($membership->user_id));

            return $callback();
        });
    }

    /**
     * Usuário da equipe com segundo fator confirmado, pronto para requisições HTTP.
     *
     * @return array{0: User, 1: Membership}
     */
    public static function staffUser(TenantScenario $tenant, SystemRole $role): array
    {
        $user = User::factory()->withTwoFactor()->create()->fresh();
        $membership = $tenant->addStaff($role, $user);

        return [$user, $membership];
    }

    public static function group(TenantScenario $tenant, string $code, string $name): CustomerGroup
    {
        return $tenant->system(static function () use ($code, $name): CustomerGroup {
            $group = new CustomerGroup;
            $group->forceFill(['code' => $code, 'name' => $name])->save();

            return $group;
        });
    }

    public static function unit(TenantScenario $tenant, BuyerCompany $company, string $code, string $name, bool $active = true): BuyerUnit
    {
        return $tenant->system(static function () use ($company, $code, $name, $active): BuyerUnit {
            $unit = new BuyerUnit;
            $unit->forceFill(['buyer_company_id' => $company->id, 'code' => $code, 'name' => $name, 'is_active' => $active])->save();

            return $unit;
        });
    }

    public static function paymentTerm(TenantScenario $tenant, string $code): PaymentTerm
    {
        return $tenant->system(static fn (): PaymentTerm => PaymentTerm::query()->where('code', $code)->firstOrFail());
    }

    public static function company(TenantScenario $tenant, string $id): BuyerCompany
    {
        return $tenant->system(static fn (): BuyerCompany => BuyerCompany::query()->findOrFail($id));
    }

    /**
     * @return Collection<int, BuyerAddress>
     */
    public static function addresses(TenantScenario $tenant, BuyerCompany $company): Collection
    {
        return $tenant->system(static fn (): Collection => BuyerAddress::query()
            ->where('buyer_company_id', $company->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get());
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public static function audits(TenantScenario $tenant, string $action): Collection
    {
        return $tenant->system(static fn (): Collection => AuditLog::query()
            ->where('action', $action)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get());
    }

    public static function auditCount(TenantScenario $tenant): int
    {
        return $tenant->system(static fn (): int => AuditLog::query()->where('action', 'not like', 'organization.%')->count());
    }

    public static function priceVersion(TenantScenario $tenant): int
    {
        return (int) $tenant->system(static fn (): mixed => DB::table('pricing_settings')
            ->where('tenant_id', $tenant->organization->id)
            ->value('price_version'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function companyData(array $overrides = []): BuyerCompanyData
    {
        $values = [
            'legalName' => 'Construtora Horizonte Verde Ltda',
            'tradeName' => 'Horizonte Verde',
            'document' => Cnpj::fromString(BrazilianDocuments::cnpj()),
            'stateRegistration' => '110.042.490.114',
            'email' => 'compras@horizonteverde.example',
            'phone' => '(11) 3456-7890',
            'notes' => null,
            'customerGroupId' => null,
            'salesRepMembershipId' => null,
            ...$overrides,
        ];

        return new BuyerCompanyData(...$values);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function addressData(array $overrides = []): BuyerAddressData
    {
        $values = [
            'kind' => AddressKind::Shipping,
            'label' => 'Obra Vila Mariana',
            'street' => 'Rua Domingos de Morais',
            'number' => '1200',
            'district' => 'Vila Mariana',
            'city' => 'São Paulo',
            'state' => 'SP',
            'postalCode' => '04010-100',
            'recipient' => 'Almoxarifado da obra',
            'complement' => null,
            'buyerUnitId' => null,
            'isDefault' => false,
            'isActive' => true,
            ...$overrides,
        ];

        return new BuyerAddressData(...$values);
    }
}
