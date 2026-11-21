<?php

declare(strict_types=1);

namespace Tests\Integration\Approvals\Admin;

use App\Enums\Identity\MembershipStatus;
use App\Models\Approvals\ApprovalDelegation;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Models\Catalog\Category;
use App\Models\Identity\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\OrderingScenario;

/**
 * Apoio aos testes da administração de regras e delegações: unidades,
 * categorias, delegações e revogação de acesso gravados no contexto de
 * sistema; leitura da auditoria do tenant.
 */
final class ApprovalAdminFixtures
{
    public static function unit(OrderingScenario $scenario, BuyerCompany $company, string $code = 'MATRIZ', bool $active = true): BuyerUnit
    {
        return $scenario->system(static function () use ($company, $code, $active): BuyerUnit {
            $unit = new BuyerUnit;
            $unit->forceFill(['buyer_company_id' => $company->id, 'code' => $code, 'name' => 'Unidade '.$code, 'is_active' => $active])->save();

            return $unit;
        });
    }

    public static function category(OrderingScenario $scenario, string $name = 'Materiais elétricos', ?Category $parent = null): Category
    {
        return $scenario->system(static function () use ($name, $parent): Category {
            $category = new Category;
            $category->forceFill(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'parent_id' => $parent?->id, 'sort_order' => 0])->save();

            return $category;
        });
    }

    public static function revoke(OrderingScenario $scenario, Membership $membership): void
    {
        $scenario->system(static fn () => Membership::query()->whereKey($membership->id)->update([
            'status' => MembershipStatus::Revoked->value,
            'revoked_at' => now(),
        ]));
    }

    /**
     * Delegação gravada diretamente (sem as regras da Action), para montar
     * delegações encerradas ou de outra empresa.
     */
    public static function delegation(OrderingScenario $scenario, Membership $from, Membership $to, CarbonImmutable $validFrom, CarbonImmutable $validUntil, ?CarbonImmutable $revokedAt = null): ApprovalDelegation
    {
        return $scenario->system(static function () use ($from, $to, $validFrom, $validUntil, $revokedAt): ApprovalDelegation {
            $delegation = new ApprovalDelegation;
            $delegation->forceFill([
                'buyer_company_id' => $from->buyer_company_id,
                'delegator_membership_id' => $from->id,
                'delegate_membership_id' => $to->id,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'reason' => 'Viagem a trabalho',
                'revoked_at' => $revokedAt,
                'created_by_user_id' => $from->user_id,
            ])->save();

            return $delegation;
        });
    }

    /**
     * Registros de auditoria da ação, do mais antigo ao mais recente.
     *
     * @return list<object{action: string, subject_id: string|null, actor_user_id: string|null, buyer_company_id: string|null, reason: string|null, data: array<string, mixed>}>
     */
    public static function audits(OrderingScenario $scenario, string $action): array
    {
        return $scenario->system(static fn (): array => DB::table('audit_logs')
            ->where('action', $action)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['action', 'subject_id', 'actor_user_id', 'buyer_company_id', 'reason', 'data'])
            ->map(static function (object $row): object {
                $row->data = json_decode((string) $row->data, true);

                return $row;
            })
            ->all());
    }
}
