<?php

declare(strict_types=1);

use App\Actions\Approvals\SaveApprovalRule;
use App\Enums\Identity\SystemRole;
use App\Livewire\Portal\Approvals\Admin\ApprovalRuleForm;
use App\Livewire\Portal\Approvals\Admin\ApprovalRulesIndex;
use App\Models\Approvals\ApprovalRule;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Approvals\Admin\ApprovalAdminFixtures as Admin;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas de regras de aprovação do administrador da empresa compradora
| (portal.approval-rules, portal.approval-rules.create,
| portal.approval-rules.edit): lista na ordem de avaliação, criação e
| edição com erros nos campos, ativação, exclusão, permissões e
| identificadores adulterados.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $this->ana = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver, User::factory()->create(['name' => 'Ana Aprovadora']));
    $this->bruno = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver, User::factory()->create(['name' => 'Bruno Aprovador']));

    $this->page = function (string $url, ?Membership $membership = null): TestResponse {
        $membership ??= $this->admin;
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))
            ->withSession(TenantScenario::sessionFor($membership))
            ->get($url);
    };

    $this->asAdmin = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->admin;
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };

    $this->rules = fn (): array => $this->scenario->system(static fn (): array => ApprovalRule::query()
        ->with('levels.approvers')
        ->orderBy('created_at')
        ->get()
        ->map(static fn (ApprovalRule $rule): array => SaveApprovalRule::snapshot($rule))
        ->all());
});

it('mostra a lista vazia e o formulário de nova regra com os aprovadores da empresa', function (): void {
    ($this->page)(route('portal.approval-rules'))
        ->assertOk()
        ->assertSeeText('Regras de aprovação')
        ->assertSeeText('Nenhuma regra de aprovação')
        ->assertSeeText('A aprovação interna não reserva estoque nem garante crédito no distribuidor');

    ($this->page)(route('portal.approval-rules.create'))
        ->assertOk()
        ->assertSeeText('Nova regra de aprovação')
        ->assertSeeText('Níveis de aprovação')
        ->assertSeeText('Ana Aprovadora')
        ->assertSeeText('Bruno Aprovador')
        ->assertSeeText('Qualquer centro de custo')
        ->assertSeeText('Solicitante');
});

it('nega as telas de regras a quem não administra a empresa', function (string $who): void {
    $membership = match ($who) {
        'comprador' => $this->buyer['membership'],
        'aprovador' => $this->ana,
        'equipe do distribuidor' => $this->scenario->tenant->ownerMembership,
    };
    $rule = Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);

    ($this->page)(route('portal.approval-rules'), $membership)->assertForbidden();
    ($this->page)(route('portal.approval-rules.create'), $membership)->assertForbidden();
    ($this->page)(route('portal.approval-rules.edit', $rule->id), $membership)->assertForbidden();
})->with(['comprador', 'aprovador', 'equipe do distribuidor']);

it('cria a regra pela tela, mostrando cada erro no campo correspondente', function (): void {
    $center = Fx::costCenter($this->scenario, $this->company);

    $component = ($this->asAdmin)(fn () => Livewire::test(ApprovalRuleForm::class)
        ->assertSet('levels', [['required' => '1', 'approvers' => []]])
        ->set('name', ' ')
        ->set('priority', 'alta')
        ->set('minTotal', 'mil reais')
        ->call('save')
        ->assertHasErrors(['priority', 'minTotal'])
        ->assertSee('Informe a prioridade como número inteiro entre -1000 e 1000.')
        ->assertSee('Informe um valor em reais, por exemplo 1.234,56.')
        ->set('priority', '5')
        ->set('minTotal', '1.500,00')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('Informe o nome da regra.')
        ->set('name', 'Obras acima de R$ 1.500')
        ->set('costCenterId', $center->id)
        ->set('levels.0.required', '2')
        ->set('levels.0.approvers', [$this->ana->id])
        ->call('save')
        ->assertHasErrors(['levels.0.required'])
        ->assertSee('O quórum do nível 1 (2) não pode ser maior que o número de aprovadores escolhidos (1).')
        ->set('levels.0.approvers', [$this->ana->id, $this->bruno->id])
        ->call('addLevel')
        ->assertSet('levels.1', ['required' => '1', 'approvers' => []])
        ->set('levels.1.approvers', [$this->ana->id])
        ->call('save')
        ->assertHasErrors(['levels.1.approvers'])
        ->assertSee('cada aprovador participa de um único nível da regra')
        ->call('removeLevel', 1)
        ->assertSet('levels', [['required' => '2', 'approvers' => [$this->ana->id, $this->bruno->id]]])
        ->call('save')
        ->assertHasNoErrors());

    $component->assertRedirect(route('portal.approval-rules'));

    expect(($this->rules)())->toBe([[
        'name' => 'Obras acima de R$ 1.500',
        'priority' => 5,
        'is_active' => true,
        'min_total_cents' => 150_000,
        'cost_center_id' => $center->id,
        'buyer_unit_id' => null,
        'category_id' => null,
        'requester_role_key' => null,
        'levels' => [['level' => 1, 'required_approvals' => 2, 'approvers' => collect([$this->ana->id, $this->bruno->id])->sort()->values()->all()]],
    ]]);
});

it('mostra no campo os erros de referência devolvidos pela regra de negócio', function (): void {
    $other = $this->scenario->tenant->addBuyerCompany();
    $foreignCenter = Fx::costCenter($this->scenario, $other, 'EXTERNO');

    ($this->asAdmin)(fn () => Livewire::test(ApprovalRuleForm::class)
        ->set('name', 'Regra com centro externo')
        ->set('costCenterId', $foreignCenter->id)
        ->set('levels.0.approvers', [$this->ana->id])
        ->call('save')
        ->assertHasErrors(['costCenterId'])
        ->assertSee('Escolha um centro de custo da sua empresa.')
        ->set('costCenterId', '')
        ->set('requesterRoleKey', SystemRole::Owner->value)
        ->call('save')
        ->assertHasErrors(['requesterRoleKey'])
        ->set('requesterRoleKey', '')
        ->set('levels.0.approvers', [$this->buyer['membership']->id])
        ->call('save')
        ->assertHasErrors(['levels.0.approvers'])
        ->assertSee('apenas usuários ativos da empresa com permissão de aprovar compras'));

    expect(($this->rules)())->toBe([]);
});

it('edita a regra com os valores gravados e pede a remoção de aprovador que perdeu o acesso', function (): void {
    $unit = Admin::unit($this->scenario, $this->company);
    $rule = Fx::rule($this->scenario, $this->company, [[1, [$this->ana, $this->bruno]]], ['min_total_cents' => 123_456, 'buyer_unit_id' => $unit->id], priority: 7, name: 'Compras da matriz');
    Admin::revoke($this->scenario, $this->bruno);

    ($this->page)(route('portal.approval-rules.edit', $rule->id))
        ->assertOk()
        ->assertSeeText('Editar regra de aprovação')
        ->assertSeeText('Sem acesso para aprovar: desmarque para salvar a regra.');

    ($this->asAdmin)(fn () => Livewire::test(ApprovalRuleForm::class, ['rule' => $rule->id])
        ->assertSet('name', 'Compras da matriz')
        ->assertSet('priority', '7')
        ->assertSet('minTotal', '1234,56')
        ->assertSet('buyerUnitId', $unit->id)
        ->assertSet('levels', [['required' => '1', 'approvers' => collect([$this->ana->id, $this->bruno->id])->sort()->values()->all()]])
        ->set('name', 'Compras da matriz e filiais')
        ->call('save')
        ->assertHasErrors(['levels.0.approvers'])
        ->set('levels.0.approvers', [$this->ana->id])
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('portal.approval-rules')));

    expect(($this->rules)()[0])->toMatchArray([
        'name' => 'Compras da matriz e filiais',
        'is_active' => false,
        'min_total_cents' => 123_456,
        'levels' => [['level' => 1, 'required_approvals' => 1, 'approvers' => [$this->ana->id]]],
    ]);
});

it('lista as regras na ordem de avaliação e permite desativar, ativar e excluir', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]], ['min_total_cents' => 500_000], priority: 1, name: 'Regra geral');
    $urgent = Fx::rule($this->scenario, $this->company, [[2, [$this->ana, $this->bruno]]], priority: 50, name: 'Regra da diretoria');

    ($this->page)(route('portal.approval-rules'))
        ->assertOk()
        ->assertSeeTextInOrder(['Regra da diretoria', 'Regra geral'])
        ->assertSeeText('Valor a partir de R$ 5.000,00')
        ->assertSeeText('Todos os pedidos')
        ->assertSeeText('2 de 2 aprovadores');

    ($this->asAdmin)(fn () => Livewire::test(ApprovalRulesIndex::class)
        ->call('deactivate', $urgent->id)
        ->assertSee('Regra Regra da diretoria desativada.')
        ->assertSee('Inativa')
        ->call('activate', $urgent->id)
        ->assertSee('Regra Regra da diretoria ativada')
        ->call('delete', $urgent->id)
        ->assertSee('Regra Regra da diretoria excluída.')
        ->assertDontSee('2 de 2 aprovadores'));

    expect(array_column(($this->rules)(), 'name'))->toBe(['Regra geral'])
        ->and(Admin::audits($this->scenario, 'approval_rule.deleted'))->toHaveCount(1);
});

it('avisa quando um nível não tem aprovadores com acesso suficientes para o quórum', function (): void {
    Fx::rule($this->scenario, $this->company, [[2, [$this->ana, $this->bruno]]], name: 'Dupla aprovação');
    Admin::revoke($this->scenario, $this->bruno);

    ($this->page)(route('portal.approval-rules'))
        ->assertOk()
        ->assertSeeText('Bruno Aprovador (sem acesso)')
        ->assertSeeText('Só 1 com acesso para quórum de 2');
});

it('responde 404 à edição de regra de outra empresa, de outro tenant ou malformada', function (string $case): void {
    $id = match ($case) {
        'outra empresa' => (function (): string {
            $other = $this->scenario->tenant->addBuyerCompany();

            return Fx::rule($this->scenario, $other, [[1, [$this->scenario->tenant->addBuyerUser($other, SystemRole::Approver)]]])->id;
        })(),
        'outro tenant' => (string) Str::uuid7(),
        'malformada' => 'regra-1',
    };

    ($this->page)(route('portal.approval-rules.edit', $id))->assertNotFound();
})->with(['outra empresa', 'outro tenant', 'malformada']);

it('trata identificadores adulterados nas ações como registro inexistente', function (): void {
    $other = $this->scenario->tenant->addBuyerCompany();
    $foreign = Fx::rule($this->scenario, $other, [[1, [$this->scenario->tenant->addBuyerUser($other, SystemRole::Approver)]]]);

    ($this->asAdmin)(fn () => Livewire::test(ApprovalRulesIndex::class)->call('delete', 'regra-1')->assertNotFound());
    ($this->asAdmin)(fn () => Livewire::test(ApprovalRulesIndex::class)->call('deactivate', $foreign->id)->assertNotFound());
    ($this->asAdmin)(fn () => Livewire::test(ApprovalRuleForm::class)
        ->set('name', 'Regra adulterada')
        ->set('levels.0.approvers', ['aprovador-1'])
        ->call('save')
        ->assertNotFound());

    expect($this->scenario->system(static fn (): bool => DB::table('approval_rules')->where('id', $foreign->id)->value('is_active')))->toBeTrue()
        ->and(fn () => ($this->asAdmin)(fn () => Livewire::test(ApprovalRuleForm::class)->set('ruleId', $foreign->id)))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('normaliza níveis enviados fora do formato esperado', function (): void {
    ($this->asAdmin)(fn () => Livewire::test(ApprovalRuleForm::class)
        ->set('levels', ['x', ['required' => ['1'], 'approvers' => 'todos']])
        ->assertSet('levels', [['required' => '', 'approvers' => []], ['required' => '', 'approvers' => []]])
        ->set('name', 'Regra')
        ->call('save')
        ->assertHasErrors(['levels.0.required', 'levels.1.required']));

    expect(($this->rules)())->toBe([]);
});
