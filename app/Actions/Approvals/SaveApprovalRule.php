<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Approvals\ApprovalRuleData;
use App\Data\Approvals\ApprovalRuleLevelData;
use App\Data\Ordering\Portal\BuyerActor;
use App\Domain\Approvals\Exceptions\InvalidApprovalRule;
use App\Domain\Shared\Money;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\Permission;
use App\Models\Approvals\ApprovalRule;
use App\Models\Approvals\ApprovalRuleLevel;
use App\Models\Approvals\ApprovalRuleLevelApprover;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Catalog\Category;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Services\Approvals\ApprovalEligibility;
use App\Services\Approvals\ApprovalRuleLock;
use App\Services\Audit\AuditRecorder;
use App\Support\Http\UntrustedId;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Cria ou altera uma regra de aprovação da empresa compradora (RF-050) e
 * ativa ou desativa regras existentes. Exige portal.approval_rules no
 * contexto de comprador da própria empresa; a gravação roda no contexto de
 * sistema do mesmo usuário, pois as regras são somente leitura no contexto
 * de comprador (ADR-024).
 *
 * - critérios opcionais: valor mínimo, centro de custo e unidade da
 *   empresa, categoria do catálogo e perfil (papel de comprador) do
 *   solicitante; critério ausente não restringe;
 * - de 1 a 10 níveis em sequência, cada um com quórum de 1 a 10 e
 *   aprovadores elegíveis (associação ativa da empresa com permissão de
 *   aprovar), sem repetir aprovador entre níveis e com quórum não maior que
 *   o número de aprovadores do nível;
 * - os níveis são regravados a cada alteração: pedidos já submetidos não
 *   mudam, porque guardam o retrato dos níveis e aprovadores
 *   (order_approval_steps); só a reavaliação que muda o total escolhe a
 *   regra de novo (ADR-023);
 * - gravações da mesma empresa são serializadas (nome único, limite de
 *   regras) pelo lock exclusivo das regras, que a escolha da regra de um
 *   pedido toma em modo compartilhado (ApprovalRuleLock), e auditadas com
 *   o estado anterior e o novo.
 */
final readonly class SaveApprovalRule
{
    public const int MAX_NAME_LENGTH = 120;

    public const int MIN_PRIORITY = -1000;

    public const int MAX_PRIORITY = 1000;

    public const int MAX_LEVELS = 10;

    public const int MAX_REQUIRED_APPROVALS = 10;

    public const int MAX_APPROVERS_PER_LEVEL = 30;

    public const int MAX_RULES_PER_COMPANY = 100;

    public function __construct(
        private PortalBuyer $buyer,
        private ApprovalEligibility $eligibility,
        private AuditRecorder $audit,
        private ApprovalRuleLock $lock,
        private TenantDatabase $db,
    ) {}

    /**
     * @throws AuthorizationException sem portal.approval_rules ou fora do contexto da empresa
     * @throws ModelNotFoundException regra ou identificador inexistente, malformado ou de outra empresa
     * @throws InvalidApprovalRule dados inválidos (detalhe "field" indica o campo)
     */
    public function handle(?string $ruleId, ApprovalRuleData $data): ApprovalRule
    {
        $actor = $this->buyer->require(Permission::PortalApprovalRules);
        $ruleId = $ruleId === null ? null : UntrustedId::uuid($ruleId, ApprovalRule::class);

        $name = self::name($data->name);
        self::assertPriority($data->priority);
        $minTotal = self::minTotal($data->minTotal);
        $costCenterId = self::optionalId($data->costCenterId, CostCenter::class);
        $unitId = self::optionalId($data->buyerUnitId, BuyerUnit::class);
        $categoryId = self::optionalId($data->categoryId, Category::class);
        $roleKey = self::optionalText($data->requesterRoleKey);
        $levels = self::levels($data->levels);

        return DB::transaction(function () use ($actor, $ruleId, $data, $name, $minTotal, $costCenterId, $unitId, $categoryId, $roleKey, $levels): ApprovalRule {
            // Regras são somente leitura no contexto de comprador (ADR-024):
            // grava no contexto de sistema do mesmo usuário; a auditoria fica
            // no contexto de quem alterou.
            [$rule, $before, $after] = $this->db->asSystem(fn (): array => $this->persist($actor, $ruleId, $data, $name, $minTotal, $costCenterId, $unitId, $categoryId, $roleKey, $levels));

            $this->audit->record(
                $before === null ? 'approval_rule.created' : 'approval_rule.updated',
                $rule,
                $before === null
                    ? ['buyer_company_id' => $actor->company->id, 'after' => $after]
                    : ['buyer_company_id' => $actor->company->id, 'before' => $before, 'after' => $after],
            );

            return $rule;
        });
    }

    /**
     * @param  list<array{required: int, approvers: list<string>}>  $levels
     * @return array{0: ApprovalRule, 1: array<string, mixed>|null, 2: array<string, mixed>}
     */
    private function persist(BuyerActor $actor, ?string $ruleId, ApprovalRuleData $data, string $name, ?Money $minTotal, ?string $costCenterId, ?string $unitId, ?string $categoryId, ?string $roleKey, array $levels): array
    {
        $this->lock->exclusive($actor->company->id);
        $rule = $ruleId === null ? null : self::lockedRule($actor, $ruleId);

        if ($rule === null && ApprovalRule::query()->where('buyer_company_id', $actor->company->id)->count() >= self::MAX_RULES_PER_COMPANY) {
            throw new InvalidApprovalRule(
                'A empresa atingiu o limite de '.self::MAX_RULES_PER_COMPANY.' regras de aprovação. Exclua regras que não usa mais.',
                ['field' => 'name'],
            );
        }

        self::assertCostCenter($actor, $costCenterId, $rule?->cost_center_id);
        self::assertUnit($actor, $unitId, $rule?->buyer_unit_id);
        self::assertCategory($categoryId);
        self::assertRequesterRole($roleKey);
        $this->assertApprovers($actor, $levels);
        self::assertUniqueName($actor, $name, $rule?->id);

        $before = $rule === null ? null : self::snapshot($rule->load('levels.approvers'));
        $rule ??= new ApprovalRule;

        $rule->forceFill([
            'buyer_company_id' => $actor->company->id,
            'name' => $name,
            'priority' => $data->priority,
            'is_active' => $data->isActive,
            'min_total_cents' => $minTotal,
            'cost_center_id' => $costCenterId,
            'buyer_unit_id' => $unitId,
            'category_id' => $categoryId,
            'requester_role_key' => $roleKey,
        ])->save();

        if ($before !== null) {
            // Aprovadores saem em cascata; os pedidos submetidos guardam o retrato.
            ApprovalRuleLevel::query()->where('approval_rule_id', $rule->id)->delete();
        }

        foreach ($levels as $index => $level) {
            $saved = new ApprovalRuleLevel;
            $saved->forceFill([
                'buyer_company_id' => $actor->company->id,
                'approval_rule_id' => $rule->id,
                'level_number' => $index + 1,
                'required_approvals' => $level['required'],
            ])->save();

            foreach ($level['approvers'] as $membershipId) {
                (new ApprovalRuleLevelApprover)->forceFill([
                    'buyer_company_id' => $actor->company->id,
                    'approval_rule_level_id' => $saved->id,
                    'membership_id' => $membershipId,
                ])->save();
            }
        }

        $rule->load('levels.approvers');

        return [$rule, $before, self::snapshot($rule)];
    }

    /**
     * Ativa ou desativa a regra. Regras inativas não são consideradas em
     * novos pedidos; pedidos já submetidos seguem os níveis retratados.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function setActive(string $ruleId, bool $active): ApprovalRule
    {
        $actor = $this->buyer->require(Permission::PortalApprovalRules);
        $ruleId = UntrustedId::uuid($ruleId, ApprovalRule::class);

        return DB::transaction(function () use ($actor, $ruleId, $active): ApprovalRule {
            [$rule, $changed] = $this->db->asSystem(function () use ($actor, $ruleId, $active): array {
                $this->lock->exclusive($actor->company->id);
                $rule = self::lockedRule($actor, $ruleId);

                if ($rule->is_active === $active) {
                    return [$rule, false];
                }

                $rule->forceFill(['is_active' => $active])->save();

                return [$rule, true];
            });

            if (! $changed) {
                return $rule;
            }

            $this->audit->record($active ? 'approval_rule.activated' : 'approval_rule.deactivated', $rule, [
                'buyer_company_id' => $actor->company->id,
                'name' => $rule->name,
            ]);

            return $rule;
        });
    }

    /**
     * Estado da regra registrado na auditoria (antes e depois).
     *
     * @return array<string, mixed>
     */
    public static function snapshot(ApprovalRule $rule): array
    {
        return [
            'name' => $rule->name,
            'priority' => $rule->priority,
            'is_active' => $rule->is_active,
            'min_total_cents' => $rule->min_total_cents?->cents,
            'cost_center_id' => $rule->cost_center_id,
            'buyer_unit_id' => $rule->buyer_unit_id,
            'category_id' => $rule->category_id,
            'requester_role_key' => $rule->requester_role_key,
            'levels' => array_values($rule->levels->map(static fn (ApprovalRuleLevel $level): array => [
                'level' => $level->level_number,
                'required_approvals' => $level->required_approvals,
                'approvers' => array_values($level->approvers->pluck('membership_id')->map(static fn (mixed $id): string => (string) $id)->sort()->values()->all()),
            ])->all()),
        ];
    }

    /**
     * Serializa as gravações de regras da mesma empresa (nome único e limite
     * de regras conferidos sem corrida), antes do bloqueio da regra.
     */
    private static function lockedRule(BuyerActor $actor, string $ruleId): ApprovalRule
    {
        return ApprovalRule::query()
            ->where('buyer_company_id', $actor->company->id)
            ->whereKey($ruleId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private static function name(string $value): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($name === '') {
            throw new InvalidApprovalRule('Informe o nome da regra.', ['field' => 'name']);
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidApprovalRule('O nome da regra deve ter até '.self::MAX_NAME_LENGTH.' caracteres.', ['field' => 'name']);
        }

        return $name;
    }

    private static function assertPriority(int $priority): void
    {
        if ($priority < self::MIN_PRIORITY || $priority > self::MAX_PRIORITY) {
            throw new InvalidApprovalRule(
                'A prioridade deve ser um número inteiro entre '.self::MIN_PRIORITY.' e '.self::MAX_PRIORITY.'.',
                ['field' => 'priority'],
            );
        }
    }

    private static function minTotal(?Money $value): ?Money
    {
        if ($value !== null && ! $value->isPositive()) {
            throw new InvalidApprovalRule(
                'O valor mínimo deve ser maior que zero. Deixe em branco para aplicar a regra a qualquer valor.',
                ['field' => 'min_total'],
            );
        }

        return $value;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private static function optionalId(?string $value, string $model): ?string
    {
        return $value === null || trim($value) === '' ? null : UntrustedId::uuid(trim($value), $model);
    }

    private static function optionalText(?string $value): ?string
    {
        $text = $value === null ? '' : trim($value);

        return $text === '' ? null : $text;
    }

    /**
     * Níveis normalizados (aprovadores sem repetição, na forma canônica).
     *
     * @param  list<ApprovalRuleLevelData>  $levels
     * @return list<array{required: int, approvers: list<string>}>
     */
    private static function levels(array $levels): array
    {
        if ($levels === []) {
            throw new InvalidApprovalRule('Inclua ao menos um nível de aprovação.', ['field' => 'levels']);
        }

        if (count($levels) > self::MAX_LEVELS) {
            throw new InvalidApprovalRule('A regra pode ter até '.self::MAX_LEVELS.' níveis de aprovação.', ['field' => 'levels']);
        }

        $normalized = [];
        $seen = [];

        foreach (array_values($levels) as $index => $level) {
            $number = $index + 1;

            if ($level->requiredApprovals < 1 || $level->requiredApprovals > self::MAX_REQUIRED_APPROVALS) {
                throw self::levelError($number, 'required_approvals', "O quórum do nível {$number} deve ser de 1 a ".self::MAX_REQUIRED_APPROVALS.' aprovações.');
            }

            $approvers = array_values(array_unique(array_map(
                static fn (string $id): string => UntrustedId::uuid($id, Membership::class),
                $level->approverIds,
            )));

            if ($approvers === []) {
                throw self::levelError($number, 'approvers', "Escolha ao menos um aprovador para o nível {$number}.");
            }

            if (count($approvers) > self::MAX_APPROVERS_PER_LEVEL) {
                throw self::levelError($number, 'approvers', "O nível {$number} pode ter até ".self::MAX_APPROVERS_PER_LEVEL.' aprovadores.');
            }

            if ($level->requiredApprovals > count($approvers)) {
                throw self::levelError(
                    $number,
                    'required_approvals',
                    "O quórum do nível {$number} ({$level->requiredApprovals}) não pode ser maior que o número de aprovadores escolhidos (".count($approvers).').',
                );
            }

            foreach ($approvers as $approver) {
                if (isset($seen[$approver])) {
                    throw self::levelError($number, 'approvers', "Um aprovador do nível {$number} já está no nível {$seen[$approver]}: cada aprovador participa de um único nível da regra.");
                }

                $seen[$approver] = $number;
            }

            $normalized[] = ['required' => $level->requiredApprovals, 'approvers' => $approvers];
        }

        return $normalized;
    }

    private static function levelError(int $number, string $attribute, string $message): InvalidApprovalRule
    {
        return new InvalidApprovalRule($message, ['field' => 'levels', 'level' => $number, 'attribute' => $attribute]);
    }

    /**
     * Centro de custo da própria empresa, ativo (ou o já gravado na regra,
     * para que a alteração de outros campos não exija trocá-lo).
     */
    private static function assertCostCenter(BuyerActor $actor, ?string $costCenterId, ?string $current): void
    {
        if ($costCenterId === null) {
            return;
        }

        $center = CostCenter::query()->where('buyer_company_id', $actor->company->id)->whereKey($costCenterId)->first();

        if ($center === null) {
            throw new InvalidApprovalRule('Escolha um centro de custo da sua empresa.', ['field' => 'cost_center']);
        }

        if (! $center->is_active && $center->id !== $current) {
            throw new InvalidApprovalRule("O centro de custo {$center->code} está inativo.", ['field' => 'cost_center']);
        }
    }

    private static function assertUnit(BuyerActor $actor, ?string $unitId, ?string $current): void
    {
        if ($unitId === null) {
            return;
        }

        $unit = BuyerUnit::query()->where('buyer_company_id', $actor->company->id)->whereKey($unitId)->first();

        if ($unit === null) {
            throw new InvalidApprovalRule('Escolha uma unidade da sua empresa.', ['field' => 'buyer_unit']);
        }

        if (! $unit->is_active && $unit->id !== $current) {
            throw new InvalidApprovalRule("A unidade {$unit->name} está inativa.", ['field' => 'buyer_unit']);
        }
    }

    private static function assertCategory(?string $categoryId): void
    {
        if ($categoryId !== null && ! Category::query()->whereKey($categoryId)->exists()) {
            throw new InvalidApprovalRule('Escolha uma categoria do catálogo.', ['field' => 'category']);
        }
    }

    private static function assertRequesterRole(?string $roleKey): void
    {
        if ($roleKey !== null && ! Role::query()->where('kind', MembershipKind::Buyer->value)->where('key', $roleKey)->exists()) {
            throw new InvalidApprovalRule('Escolha um dos perfis de usuário da empresa compradora.', ['field' => 'requester_role']);
        }
    }

    /**
     * @param  list<array{required: int, approvers: list<string>}>  $levels
     */
    private function assertApprovers(BuyerActor $actor, array $levels): void
    {
        $all = array_merge(...array_map(static fn (array $level): array => $level['approvers'], $levels));
        $eligible = array_flip($this->eligibility->eligible($actor->company->id, $all));

        foreach ($levels as $index => $level) {
            foreach ($level['approvers'] as $approver) {
                if (! isset($eligible[$approver])) {
                    $number = $index + 1;

                    throw self::levelError($number, 'approvers', "Escolha para o nível {$number} apenas usuários ativos da empresa com permissão de aprovar compras.");
                }
            }
        }
    }

    private static function assertUniqueName(BuyerActor $actor, string $name, ?string $ruleId): void
    {
        $query = ApprovalRule::query()
            ->where('buyer_company_id', $actor->company->id)
            ->whereRaw('lower(name) = lower(?)', [$name]);

        if ($ruleId !== null) {
            $query->whereKeyNot($ruleId);
        }

        if ($query->exists()) {
            throw new InvalidApprovalRule('Já existe uma regra com este nome na empresa.', ['field' => 'name']);
        }
    }
}
