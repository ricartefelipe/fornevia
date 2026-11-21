<?php

declare(strict_types=1);

namespace Tests\Integration\Credit;

use App\Actions\Credit\GrantCreditException;
use App\Actions\Credit\OpenCreditAccount;
use App\Domain\Credit\CreditPosition;
use App\Domain\Shared\Money;
use App\Enums\Credit\CreditMovementType;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Buyers\BuyerCompany;
use App\Models\Credit\CreditAccount;
use App\Models\Credit\CreditException;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Services\Credit\CreditLedger;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Builders\TenantScenario;

/**
 * Apoio aos testes de crédito comercial: contas e exceções criadas pelas
 * Actions (como o proprietário do distribuidor), movimentos do ledger
 * inseridos no contexto de sistema (como fariam pedidos, faturamento e
 * pagamentos), relógio controlado e leituras para conferência.
 */
final class CreditFixtures
{
    /** Quarta-feira, 12h em São Paulo. */
    public const string NOW = '2026-11-18T15:00:00Z';

    public static function freezeClock(string $instant = self::NOW): FrozenClock
    {
        $clock = FrozenClock::at($instant);
        app()->instance(Clock::class, $clock);

        return $clock;
    }

    /**
     * Executa como a associação, com o usuário autenticado no Livewire.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asMember(TenantScenario $tenant, Membership $membership, Closure $callback): mixed
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

        return [$user, $tenant->addStaff($role, $user)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function company(TenantScenario $tenant, string $tradeName, array $attributes = []): BuyerCompany
    {
        return $tenant->addBuyerCompany(['trade_name' => $tradeName, 'legal_name' => $tradeName.' Ltda', ...$attributes]);
    }

    public static function open(TenantScenario $tenant, BuyerCompany $company, string $limit, int $toleranceDays = 0): CreditAccount
    {
        return $tenant->actingAs($tenant->ownerMembership, static fn (): CreditAccount => app(OpenCreditAccount::class)
            ->handle($company, Money::parse($limit), $toleranceDays, 'Limite aprovado pelo comitê de crédito'));
    }

    public static function grant(TenantScenario $tenant, BuyerCompany $company, string $amount, string $firstDay, string $lastDay): CreditException
    {
        return $tenant->actingAs($tenant->ownerMembership, static fn (): CreditException => app(GrantCreditException::class)
            ->handle($company, Money::parse($amount), CarbonImmutable::parse($firstDay), CarbonImmutable::parse($lastDay), 'Campanha sazonal de fim de ano'));
    }

    /**
     * Movimento do ledger no contexto de sistema (o trigger atualiza a projeção).
     */
    public static function movement(TenantScenario $tenant, CreditAccount $account, CreditMovementType $type, int $committedDelta, int $receivableDelta): string
    {
        return $tenant->system(static function () use ($account, $type, $committedDelta, $receivableDelta): string {
            $reference = (string) Str::uuid7();

            DB::table('credit_movements')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $account->tenant_id,
                'credit_account_id' => $account->id,
                'buyer_company_id' => $account->buyer_company_id,
                'type' => $type->value,
                'committed_delta' => $committedDelta,
                'receivable_delta' => $receivableDelta,
                'reference_type' => $type === CreditMovementType::OrderCommitment || $type === CreditMovementType::CommitmentRelease ? 'order' : 'invoice',
                'reference_id' => $reference,
                'created_at' => now(),
            ]);

            return $reference;
        });
    }

    public static function position(TenantScenario $tenant, BuyerCompany $company): CreditPosition
    {
        return $tenant->system(static fn (): CreditPosition => app(CreditLedger::class)->positionFor($company->id));
    }

    public static function account(TenantScenario $tenant, BuyerCompany $company): ?CreditAccount
    {
        return $tenant->system(static fn (): ?CreditAccount => CreditAccount::query()->where('buyer_company_id', $company->id)->first());
    }

    /**
     * Histórico de limite: [limite anterior, novo limite, situação anterior, nova situação, justificativa, responsável].
     *
     * @return list<array{0: int, 1: int, 2: string, 3: string, 4: string, 5: string}>
     */
    public static function limitChanges(TenantScenario $tenant, ?CreditAccount $account = null): array
    {
        return $tenant->system(static fn (): array => DB::table('credit_limit_changes')
            ->when($account !== null, static fn ($query) => $query->where('credit_account_id', $account?->id))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => [
                (int) $row->previous_limit_cents,
                (int) $row->new_limit_cents,
                (string) $row->previous_status,
                (string) $row->new_status,
                (string) $row->reason,
                (string) $row->changed_by_user_id,
            ])
            ->all());
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

    public static function creditAuditCount(TenantScenario $tenant): int
    {
        return $tenant->system(static fn (): int => AuditLog::query()->where('action', 'like', 'credit_%')->count());
    }
}
