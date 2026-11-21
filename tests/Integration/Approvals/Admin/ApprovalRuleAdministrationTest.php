<?php

declare(strict_types=1);

use App\Actions\Approvals\DeleteApprovalRule;
use App\Actions\Approvals\SaveApprovalRule;
use App\Data\Approvals\ApprovalRuleData;
use App\Data\Approvals\ApprovalRuleLevelData;
use App\Domain\Approvals\Exceptions\InvalidApprovalRule;
use App\Domain\Shared\Money;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Approvals\ApprovalRule;
use App\Models\Approvals\ApprovalRuleLevel;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Approvals\Admin\ApprovalAdminFixtures as Admin;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Administração das regras de aprovação (RF-050) pelo administrador da
| empresa compradora: criação, alteração, ativação, exclusão, validação dos
| critérios e níveis, autorização, isolamento e auditoria. Pedidos já
| submetidos mantêm os níveis retratados.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $this->ana = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->bruno = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->carla = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);

    $this->data = fn (array $overrides = []): ApprovalRuleData => new ApprovalRuleData(...[
        'name' => 'Compras acima do limite',
        'priority' => 10,
        'isActive' => true,
        'levels' => [new ApprovalRuleLevelData(1, [$this->ana->id])],
        ...$overrides,
    ]);
    $this->save = fn (?string $ruleId, ApprovalRuleData $data, ?Membership $who = null): ApprovalRule => $this->scenario->tenant->actingAs(
        $who ?? $this->admin,
        static fn (): ApprovalRule => app(SaveApprovalRule::class)->handle($ruleId, $data),
    );
    $this->setActive = fn (string $ruleId, bool $active, ?Membership $who = null): ApprovalRule => $this->scenario->tenant->actingAs(
        $who ?? $this->admin,
        static fn (): ApprovalRule => app(SaveApprovalRule::class)->setActive($ruleId, $active),
    );
    $this->delete = fn (string $ruleId, ?Membership $who = null): string => $this->scenario->tenant->actingAs(
        $who ?? $this->admin,
        static fn (): string => app(DeleteApprovalRule::class)->handle($ruleId),
    );
    $this->stored = fn (): array => $this->scenario->system(static fn (): array => ApprovalRule::query()
        ->with('levels.approvers')
        ->orderBy('created_at')
        ->get()
        ->map(static fn (ApprovalRule $rule): array => SaveApprovalRule::snapshot($rule))
        ->all());
    $this->rejects = function (ApprovalRuleData $data, string $field, string $message, ?string $ruleId = null): InvalidApprovalRule {
        try {
            ($this->save)($ruleId, $data);
        } catch (InvalidApprovalRule $e) {
            expect($e->getMessage())->toContain($message)
                ->and($e->details()['field'] ?? null)->toBe($field);

            return $e;
        }

        throw new RuntimeException('A regra deveria ter sido recusada.');
    };
});

it('cria a regra com critérios e níveis em sequência e registra a auditoria da empresa', function (): void {
    $center = Fx::costCenter($this->scenario, $this->company);
    $unit = Admin::unit($this->scenario, $this->company);
    $category = Admin::category($this->scenario);

    $rule = ($this->save)(null, ($this->data)([
        'name' => '  Obras   acima de R$ 1.000 ',
        'priority' => -5,
        'minTotal' => Money::parse('1.000,00'),
        'costCenterId' => $center->id,
        'buyerUnitId' => strtoupper($unit->id),
        'categoryId' => $category->id,
        'requesterRoleKey' => SystemRole::Requester->value,
        'levels' => [
            new ApprovalRuleLevelData(2, [$this->ana->id, strtoupper($this->bruno->id), $this->ana->id]),
            new ApprovalRuleLevelData(1, [$this->carla->id]),
        ],
    ]));

    $expected = [
        'name' => 'Obras acima de R$ 1.000',
        'priority' => -5,
        'is_active' => true,
        'min_total_cents' => 100_000,
        'cost_center_id' => $center->id,
        'buyer_unit_id' => $unit->id,
        'category_id' => $category->id,
        'requester_role_key' => 'requester',
        'levels' => [
            ['level' => 1, 'required_approvals' => 2, 'approvers' => collect([$this->ana->id, $this->bruno->id])->sort()->values()->all()],
            ['level' => 2, 'required_approvals' => 1, 'approvers' => [$this->carla->id]],
        ],
    ];

    expect(($this->stored)())->toBe([$expected])
        ->and($rule->buyer_company_id)->toBe($this->company->id);

    $audits = Admin::audits($this->scenario, 'approval_rule.created');

    expect($audits)->toHaveCount(1)
        ->and($audits[0]->subject_id)->toBe($rule->id)
        ->and($audits[0]->actor_user_id)->toBe($this->admin->user_id)
        ->and($audits[0]->buyer_company_id)->toBe($this->company->id)
        // jsonb não preserva a ordem das chaves.
        ->and($audits[0]->data['after'])->toEqual($expected);
});

it('aplica a regra criada pelo administrador aos pedidos seguintes', function (): void {
    ($this->save)(null, ($this->data)(['minTotal' => Money::ofCents(20_000)]));

    $order = Fx::fresh($this->scenario, Fx::place($this->scenario, $this->buyer));

    expect($order->commercial_status)->toBe(CommercialStatus::PendingApproval)
        ->and($order->approval_rule_name)->toBe('Compras acima do limite')
        ->and($order->approvalSteps->map(static fn ($step): array => $step->approver_membership_ids)->all())->toBe([[$this->ana->id]]);
});

it('altera a regra sem afetar os níveis retratados nos pedidos já submetidos', function (): void {
    $rule = ($this->save)(null, ($this->data)());
    $submitted = Fx::place($this->scenario, $this->buyer);

    ($this->save)($rule->id, ($this->data)([
        'name' => 'Compras com dois níveis',
        'levels' => [new ApprovalRuleLevelData(1, [$this->bruno->id]), new ApprovalRuleLevelData(1, [$this->carla->id])],
    ]));

    $before = Fx::fresh($this->scenario, $submitted);
    $after = Fx::fresh($this->scenario, Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true)));

    expect($before->approval_rule_name)->toBe('Compras acima do limite')
        ->and($before->approvalSteps->map(static fn ($step): array => [$step->level_number, $step->approver_membership_ids])->all())->toBe([[1, [$this->ana->id]]])
        ->and($after->approval_rule_name)->toBe('Compras com dois níveis')
        ->and($after->approvalSteps->map(static fn ($step): array => [$step->level_number, $step->approver_membership_ids])->all())->toBe([[1, [$this->bruno->id]], [2, [$this->carla->id]]])
        ->and($this->scenario->system(static fn (): int => ApprovalRuleLevel::query()->count()))->toBe(2);

    $audit = Admin::audits($this->scenario, 'approval_rule.updated')[0];

    expect($audit->data['before']['name'])->toBe('Compras acima do limite')
        ->and($audit->data['before']['levels'])->toEqual([['level' => 1, 'required_approvals' => 1, 'approvers' => [$this->ana->id]]])
        ->and($audit->data['after']['levels'])->toHaveCount(2)
        ->and($audit->buyer_company_id)->toBe($this->company->id);
});

it('recusa dados inválidos indicando o campo', function (string $case, string $field, string $message): void {
    $other = $this->scenario->tenant->addBuyerCompany();
    $level = fn (int $required, array $approvers): ApprovalRuleLevelData => new ApprovalRuleLevelData($required, array_map(static fn (Membership $m): string => $m->id, $approvers));

    $overrides = match ($case) {
        'nome vazio' => ['name' => "  \n "],
        'nome longo' => ['name' => str_repeat('a', 121)],
        'prioridade acima' => ['priority' => 1001],
        'prioridade abaixo' => ['priority' => -1001],
        'valor mínimo zero' => ['minTotal' => Money::zero()],
        'valor mínimo negativo' => ['minTotal' => Money::ofCents(-1)],
        'sem níveis' => ['levels' => []],
        'onze níveis' => ['levels' => array_fill(0, 11, $level(1, [$this->ana]))],
        'quórum zero' => ['levels' => [$level(0, [$this->ana])]],
        'quórum onze' => ['levels' => [$level(11, [$this->ana])]],
        'quórum maior que aprovadores' => ['levels' => [$level(1, [$this->ana]), $level(2, [$this->bruno])]],
        'nível sem aprovador' => ['levels' => [$level(1, [])]],
        'aprovador em dois níveis' => ['levels' => [$level(1, [$this->ana]), $level(1, [$this->bruno, $this->ana])]],
        'aprovador sem permissão' => ['levels' => [$level(1, [$this->buyer['membership']])]],
        'aprovador revogado' => (function (): array {
            Admin::revoke($this->scenario, $this->carla);

            return ['levels' => [new ApprovalRuleLevelData(1, [$this->ana->id, $this->carla->id])]];
        })(),
        'aprovador de outra empresa' => ['levels' => [$level(1, [$this->scenario->tenant->addBuyerUser($other, SystemRole::Approver)])]],
        'aprovador inexistente' => ['levels' => [new ApprovalRuleLevelData(1, [(string) Str::uuid7()])]],
        'centro de custo de outra empresa' => ['costCenterId' => Fx::costCenter($this->scenario, $other)->id],
        'centro de custo inativo' => (function (): array {
            $center = Fx::costCenter($this->scenario, $this->company, 'ANTIGO');
            $this->scenario->system(static fn () => DB::table('cost_centers')->where('id', $center->id)->update(['is_active' => false]));

            return ['costCenterId' => $center->id];
        })(),
        'unidade de outra empresa' => ['buyerUnitId' => Admin::unit($this->scenario, $other)->id],
        'unidade inativa' => ['buyerUnitId' => Admin::unit($this->scenario, $this->company, 'FILIAL', active: false)->id],
        'categoria inexistente' => ['categoryId' => (string) Str::uuid7()],
        'perfil de equipe' => ['requesterRoleKey' => SystemRole::Admin->value],
        'perfil inexistente' => ['requesterRoleKey' => 'diretoria'],
    };

    ($this->rejects)(($this->data)($overrides), $field, $message);

    expect(($this->stored)())->toBe([]);
})->with([
    ['nome vazio', 'name', 'Informe o nome da regra.'],
    ['nome longo', 'name', 'até 120 caracteres'],
    ['prioridade acima', 'priority', 'entre -1000 e 1000'],
    ['prioridade abaixo', 'priority', 'entre -1000 e 1000'],
    ['valor mínimo zero', 'min_total', 'maior que zero'],
    ['valor mínimo negativo', 'min_total', 'maior que zero'],
    ['sem níveis', 'levels', 'ao menos um nível'],
    ['onze níveis', 'levels', 'até 10 níveis'],
    ['quórum zero', 'levels', 'O quórum do nível 1 deve ser de 1 a 10'],
    ['quórum onze', 'levels', 'O quórum do nível 1 deve ser de 1 a 10'],
    ['quórum maior que aprovadores', 'levels', 'O quórum do nível 2 (2) não pode ser maior que o número de aprovadores escolhidos (1)'],
    ['nível sem aprovador', 'levels', 'Escolha ao menos um aprovador para o nível 1.'],
    ['aprovador em dois níveis', 'levels', 'já está no nível 1'],
    ['aprovador sem permissão', 'levels', 'apenas usuários ativos da empresa com permissão de aprovar'],
    ['aprovador revogado', 'levels', 'apenas usuários ativos da empresa com permissão de aprovar'],
    ['aprovador de outra empresa', 'levels', 'apenas usuários ativos da empresa com permissão de aprovar'],
    ['aprovador inexistente', 'levels', 'apenas usuários ativos da empresa com permissão de aprovar'],
    ['centro de custo de outra empresa', 'cost_center', 'Escolha um centro de custo da sua empresa.'],
    ['centro de custo inativo', 'cost_center', 'O centro de custo ANTIGO está inativo.'],
    ['unidade de outra empresa', 'buyer_unit', 'Escolha uma unidade da sua empresa.'],
    ['unidade inativa', 'buyer_unit', 'está inativa'],
    ['categoria inexistente', 'category', 'Escolha uma categoria do catálogo.'],
    ['perfil de equipe', 'requester_role', 'perfis de usuário da empresa compradora'],
    ['perfil inexistente', 'requester_role', 'perfis de usuário da empresa compradora'],
]);

it('informa o nível e o atributo do erro de nível', function (): void {
    $error = ($this->rejects)(($this->data)(['levels' => [
        new ApprovalRuleLevelData(1, [$this->ana->id]),
        new ApprovalRuleLevelData(3, [$this->bruno->id]),
    ]]), 'levels', 'nível 2');

    expect($error->details())->toBe(['field' => 'levels', 'level' => 2, 'attribute' => 'required_approvals']);
});

it('recusa nome repetido na mesma empresa, sem diferenciar maiúsculas, e aceita em outra empresa', function (): void {
    $rule = ($this->save)(null, ($this->data)());

    ($this->rejects)(($this->data)(['name' => 'COMPRAS acima do limite']), 'name', 'Já existe uma regra com este nome');

    // A própria regra mantém o nome ao ser alterada.
    ($this->save)($rule->id, ($this->data)(['priority' => 20]));

    $other = $this->scenario->tenant->addBuyerCompany();
    $otherAdmin = $this->scenario->tenant->addBuyerUser($other, SystemRole::BuyerAdmin);
    $otherApprover = $this->scenario->tenant->addBuyerUser($other, SystemRole::Approver);
    ($this->save)(null, ($this->data)(['levels' => [new ApprovalRuleLevelData(1, [$otherApprover->id])]]), $otherAdmin);

    expect($this->scenario->system(static fn (): int => ApprovalRule::query()->where('name', 'Compras acima do limite')->count()))->toBe(2);
});

it('mantém o centro de custo e a unidade já gravados mesmo depois de inativados', function (): void {
    $center = Fx::costCenter($this->scenario, $this->company);
    $unit = Admin::unit($this->scenario, $this->company);
    $rule = ($this->save)(null, ($this->data)(['costCenterId' => $center->id, 'buyerUnitId' => $unit->id]));

    $this->scenario->system(static function () use ($center, $unit): void {
        DB::table('cost_centers')->where('id', $center->id)->update(['is_active' => false]);
        DB::table('buyer_units')->where('id', $unit->id)->update(['is_active' => false]);
    });

    $saved = ($this->save)($rule->id, ($this->data)(['name' => 'Obras', 'costCenterId' => $center->id, 'buyerUnitId' => $unit->id]));

    expect($saved->name)->toBe('Obras')
        ->and($saved->cost_center_id)->toBe($center->id)
        ->and($saved->buyer_unit_id)->toBe($unit->id);
});

it('limita a quantidade de regras por empresa', function (): void {
    $this->scenario->system(function (): void {
        for ($i = 0; $i < SaveApprovalRule::MAX_RULES_PER_COMPANY; $i++) {
            (new ApprovalRule)->forceFill(['buyer_company_id' => $this->company->id, 'name' => "Regra {$i}", 'priority' => 0, 'is_active' => false])->save();
        }
    });

    ($this->rejects)(($this->data)(), 'name', 'limite de 100 regras');
});

it('ativa e desativa a regra com auditoria, e a regra inativa deixa de valer para novos pedidos', function (): void {
    $rule = ($this->save)(null, ($this->data)());

    expect(($this->setActive)($rule->id, false)->is_active)->toBeFalse()
        ->and(($this->setActive)($rule->id, false)->is_active)->toBeFalse();

    $order = Fx::fresh($this->scenario, Fx::place($this->scenario, $this->buyer));

    expect($order->commercial_status)->toBe(CommercialStatus::Confirmed)
        ->and($order->approval_status)->toBe(ApprovalStatus::NotRequired);

    ($this->setActive)($rule->id, true);

    expect(Admin::audits($this->scenario, 'approval_rule.deactivated'))->toHaveCount(1)
        ->and(Admin::audits($this->scenario, 'approval_rule.activated'))->toHaveCount(1)
        ->and(Admin::audits($this->scenario, 'approval_rule.activated')[0]->buyer_company_id)->toBe($this->company->id);
});

it('exclui a regra com níveis e aprovadores, sem afetar pedidos já submetidos', function (): void {
    $rule = ($this->save)(null, ($this->data)(['levels' => [new ApprovalRuleLevelData(2, [$this->ana->id, $this->bruno->id])]]));
    $submitted = Fx::place($this->scenario, $this->buyer);

    expect(($this->delete)($rule->id))->toBe('Compras acima do limite');

    $order = Fx::fresh($this->scenario, $submitted);

    expect(($this->stored)())->toBe([])
        ->and($this->scenario->system(static fn (): array => [
            DB::table('approval_rule_levels')->count(),
            DB::table('approval_rule_level_approvers')->count(),
        ]))->toBe([0, 0])
        ->and($order->commercial_status)->toBe(CommercialStatus::PendingApproval)
        ->and($order->approval_rule_name)->toBe('Compras acima do limite')
        ->and($order->approvalSteps)->toHaveCount(1)
        ->and($order->approvalSteps[0]->required_approvals)->toBe(2);

    $audit = Admin::audits($this->scenario, 'approval_rule.deleted')[0];

    expect($audit->subject_id)->toBe($rule->id)
        ->and($audit->buyer_company_id)->toBe($this->company->id)
        ->and($audit->data['before']['levels'][0]['required_approvals'])->toBe(2);
});

it('exige a permissão de gerenciar regras no contexto de comprador', function (string $who): void {
    $rule = ($this->save)(null, ($this->data)());
    $membership = match ($who) {
        'comprador' => $this->buyer['membership'],
        'aprovador' => $this->ana,
        'solicitante' => $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Requester),
        'equipe do distribuidor' => $this->scenario->tenant->ownerMembership,
    };

    expect(fn () => ($this->save)(null, ($this->data)(['name' => 'Outra']), $membership))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($rule->id, ($this->data)(['name' => 'Outra']), $membership))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->setActive)($rule->id, false, $membership))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->delete)($rule->id, $membership))->toThrow(AuthorizationException::class);

    expect(($this->stored)())->toHaveCount(1)
        ->and(($this->stored)()[0]['is_active'])->toBeTrue();
})->with(['comprador', 'aprovador', 'solicitante', 'equipe do distribuidor']);

it('trata regra de outra empresa, de outro tenant ou malformada como inexistente', function (string $case): void {
    $ruleId = match ($case) {
        'outra empresa' => (function (): string {
            $other = $this->scenario->tenant->addBuyerCompany();

            return Fx::rule($this->scenario, $other, [[1, [$this->scenario->tenant->addBuyerUser($other, SystemRole::Approver)]]])->id;
        })(),
        'outro tenant' => (function (): string {
            $foreign = OrderingScenario::create();
            $company = $foreign->tenant->addBuyerCompany();

            return Fx::rule($foreign, $company, [[1, [$foreign->tenant->addBuyerUser($company, SystemRole::Approver)]]])->id;
        })(),
        'malformada' => 'regra-1',
    };

    expect(fn () => ($this->save)($ruleId, ($this->data)()))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->setActive)($ruleId, false))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->delete)($ruleId))->toThrow(ModelNotFoundException::class)
        ->and(Admin::audits($this->scenario, 'approval_rule.updated'))->toBe([])
        ->and(Admin::audits($this->scenario, 'approval_rule.deleted'))->toBe([]);
})->with(['outra empresa', 'outro tenant', 'malformada']);

it('trata identificadores adulterados como registro inexistente', function (array $overrides): void {
    expect(fn () => ($this->save)(null, ($this->data)($overrides)))->toThrow(ModelNotFoundException::class)
        ->and(($this->stored)())->toBe([]);
})->with([
    'aprovador' => [['levels' => [new ApprovalRuleLevelData(1, ['aprovador-1'])]]],
    'centro de custo' => [['costCenterId' => "1' or '1'='1"]],
    'unidade' => [['buyerUnitId' => 'unidade']],
    'categoria' => [['categoryId' => '12345']],
]);
