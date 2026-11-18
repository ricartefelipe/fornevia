<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Data\Identity\CreateOrganizationData;
use App\Domain\Platform\Exceptions\OrganizationAlreadyRegistered;
use App\Domain\Platform\Exceptions\PlanUnavailable;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\OrganizationStatus;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Enums\Platform\SubscriptionStatus;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Identity\Branch;
use App\Models\Identity\Membership;
use App\Models\Identity\Organization;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Pricing\PaymentTerm;
use App\Models\Pricing\PricingPolicy;
use App\Models\Pricing\PricingSettings;
use App\Services\Audit\AuditRecorder;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Onboarding: cria o tenant, a assinatura em trial, os papéis padrão, a
 * matriz e a associação de proprietário, tudo em uma única transação.
 */
final readonly class CreateOrganization
{
    public function __construct(
        private TenantDatabase $db,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(User $owner, CreateOrganizationData $data): Membership
    {
        $plan = Plan::query()
            ->where('code', $data->planCode)
            ->where('is_active', true)
            ->where('is_public', true)
            ->first() ?? throw new PlanUnavailable('Plano indisponível para contratação.');

        if (Organization::query()->where('document', $data->document->digits)->exists()) {
            throw new OrganizationAlreadyRegistered('Já existe uma organização cadastrada com este CNPJ.');
        }

        $organizationId = (string) Str::uuid7();

        try {
            return $this->db->run(
                ExecutionContext::system($organizationId, $owner->id),
                fn (): Membership => $this->create($organizationId, $owner, $plan, $data),
            );
        } catch (UniqueConstraintViolationException) {
            throw new OrganizationAlreadyRegistered('Já existe uma organização cadastrada com este CNPJ.');
        }
    }

    /**
     * Unidades de medida, condições de pagamento e configuração de preços
     * iniciais do tenant (editáveis pela equipe).
     */
    private function createCommercialDefaults(): void
    {
        foreach ([['UN', 'Unidade', 0], ['PC', 'Peça', 0], ['CX', 'Caixa', 0], ['PCT', 'Pacote', 0], ['PAR', 'Par', 0],
            ['RL', 'Rolo', 0], ['M', 'Metro', 2], ['KG', 'Quilograma', 3], ['L', 'Litro', 3]] as [$code, $name, $decimals]) {
            $unit = new UnitOfMeasure;
            $unit->forceFill(['code' => $code, 'name' => $name, 'decimals' => $decimals])->save();
        }

        foreach ([['AVISTA', 'À vista', [0], false], ['28D', '28 dias', [28], true], ['30-60', '30/60 dias', [30, 60], true],
            ['30-60-90', '30/60/90 dias', [30, 60, 90], true]] as [$code, $name, $days, $credit]) {
            $term = new PaymentTerm;
            $term->forceFill(['code' => $code, 'name' => $name, 'installment_days' => $days, 'requires_credit' => $credit, 'is_active' => true])->save();
        }

        (new PricingSettings)->forceFill(['price_version' => 1])->save();
        (new PricingPolicy)->forceFill(['min_margin_bps' => 1_000])->save();
    }

    private function create(string $organizationId, User $owner, Plan $plan, CreateOrganizationData $data): Membership
    {
        $now = $this->clock->now();

        $organization = new Organization;
        $organization->forceFill([
            'id' => $organizationId,
            'legal_name' => $data->legalName,
            'trade_name' => $data->tradeName,
            'document' => $data->document->digits,
            'slug' => Str::slug($data->tradeName).'-'.Str::lower(Str::random(6)),
            'status' => OrganizationStatus::Trial,
            'email' => $data->email,
            'phone' => $data->phone,
        ])->save();

        $subscription = new Subscription;
        $subscription->forceFill([
            'organization_id' => $organizationId,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => $now->addDays($plan->trial_days),
            'current_period_start' => $now,
            'current_period_end' => $now->addDays($plan->trial_days),
        ])->save();

        $ownerRoleId = null;

        foreach (SystemRole::cases() as $template) {
            $role = new Role;
            $role->forceFill([
                'key' => $template->value,
                'name' => $template->label(),
                'kind' => $template->kind(),
                'permissions' => array_map(static fn (Permission $p): string => $p->value, $template->permissions()),
                'max_discount_bps' => $template->maxDiscountBps(),
                'is_system' => true,
            ])->save();

            if ($template === SystemRole::Owner) {
                $ownerRoleId = $role->id;
            }
        }

        $headquarters = new Branch;
        $headquarters->forceFill([
            'code' => 'MATRIZ',
            'name' => 'Matriz',
            'document' => $data->document->digits,
            'is_headquarters' => true,
            'is_active' => true,
        ])->save();

        $membership = new Membership;
        $membership->forceFill([
            'user_id' => $owner->id,
            'kind' => MembershipKind::Staff,
            'role_id' => $ownerRoleId,
            'branch_id' => $headquarters->id,
            'status' => MembershipStatus::Active,
        ])->save();

        $this->createCommercialDefaults();

        $this->audit->record('organization.created', $organization, [
            'plan' => $plan->code,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
        ]);

        DB::table('platform_audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'actor_user_id' => $owner->id,
            'organization_id' => $organizationId,
            'action' => 'organization.created',
            'data' => json_encode(['plan' => $plan->code], JSON_THROW_ON_ERROR),
            'correlation_id' => Context::get('correlation_id'),
            'created_at' => $now,
        ]);

        return $membership;
    }
}
