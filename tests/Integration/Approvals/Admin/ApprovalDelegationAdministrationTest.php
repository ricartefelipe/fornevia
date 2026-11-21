<?php

declare(strict_types=1);

use App\Actions\Approvals\RevokeApprovalDelegation;
use App\Actions\Approvals\SaveApprovalDelegation;
use App\Data\Approvals\ApprovalDelegationData;
use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Identity\SystemRole;
use App\Models\Approvals\ApprovalDelegation;
use App\Models\Identity\Membership;
use App\Services\Approvals\ApprovalEligibility;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Approvals\Admin\ApprovalAdminFixtures as Admin;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Delegações de aprovação (RN-053): o aprovador delega as próprias
| aprovações e o administrador da empresa delega por qualquer aprovador,
| com prazo de até 90 dias, motivo e auditoria; revogação pelo titular ou
| pelo administrador. A delegação vale na decisão do pedido.
*/

beforeEach(function (): void {
    $this->clock = new FrozenClock(CarbonImmutable::now('UTC')->startOfSecond());
    app()->instance(Clock::class, $this->clock);
    $this->today = $this->clock->today();

    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $this->ana = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->bruno = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->carla = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);

    $this->data = fn (Membership $from, Membership $to, int $startIn = 0, int $days = 7, string $reason = 'Férias da titular'): ApprovalDelegationData => new ApprovalDelegationData(
        $from->id,
        $to->id,
        $this->today->addDays($startIn)->toDateString(),
        $this->today->addDays($startIn + $days - 1)->toDateString(),
        $reason,
    );
    $this->delegate = fn (ApprovalDelegationData $data, ?Membership $who = null): ApprovalDelegation => $this->scenario->tenant->actingAs(
        $who ?? $this->admin,
        static fn (): ApprovalDelegation => app(SaveApprovalDelegation::class)->handle($data),
    );
    $this->revoke = fn (string $id, ?Membership $who = null, ?string $reason = null): ApprovalDelegation => $this->scenario->tenant->actingAs(
        $who ?? $this->admin,
        static fn (): ApprovalDelegation => app(RevokeApprovalDelegation::class)->handle($id, $reason),
    );
    $this->count = fn (): int => $this->scenario->system(static fn (): int => ApprovalDelegation::query()->count());
    $this->rejects = function (ApprovalDelegationData $data, string $field, string $message, ?Membership $who = null): void {
        try {
            ($this->delegate)($data, $who);
        } catch (ApprovalNotAllowed $e) {
            expect($e->getMessage())->toContain($message)
                ->and($e->details()['field'] ?? null)->toBe($field);

            return;
        }

        throw new RuntimeException('A delegação deveria ter sido recusada.');
    };
});

it('registra a delegação do administrador por um aprovador, em dias do fuso do negócio, com auditoria', function (): void {
    $delegation = ($this->delegate)(($this->data)($this->ana, $this->bruno, reason: '  Férias de dezembro  '));
    $stored = $this->scenario->system(static fn (): ApprovalDelegation => ApprovalDelegation::query()->findOrFail($delegation->id));
    $timezone = (string) config('app.business_timezone');

    expect($stored->buyer_company_id)->toBe($this->company->id)
        ->and($stored->delegator_membership_id)->toBe($this->ana->id)
        ->and($stored->delegate_membership_id)->toBe($this->bruno->id)
        ->and($stored->valid_from->equalTo(CarbonImmutable::parse($this->today->toDateString(), $timezone)->startOfDay()))->toBeTrue()
        ->and($stored->valid_until->equalTo(CarbonImmutable::parse($this->today->addDays(7)->toDateString(), $timezone)->startOfDay()))->toBeTrue()
        ->and($stored->reason)->toBe('Férias de dezembro')
        ->and($stored->revoked_at)->toBeNull()
        ->and($stored->created_by_user_id)->toBe($this->admin->user_id);

    $audit = Admin::audits($this->scenario, 'approval_delegation.created')[0];

    expect($audit->subject_id)->toBe($delegation->id)
        ->and($audit->actor_user_id)->toBe($this->admin->user_id)
        ->and($audit->buyer_company_id)->toBe($this->company->id)
        ->and($audit->reason)->toBe('Férias de dezembro')
        ->and($audit->data)->toMatchArray([
            'delegator_membership_id' => $this->ana->id,
            'delegate_membership_id' => $this->bruno->id,
            'first_day' => $this->today->toDateString(),
            'last_day' => $this->today->addDays(6)->toDateString(),
            'by_administrator' => true,
        ]);
});

it('permite ao aprovador delegar as próprias aprovações, mas não as de outro aprovador', function (): void {
    ($this->delegate)(($this->data)($this->ana, $this->bruno), $this->ana);

    expect(fn () => ($this->delegate)(($this->data)($this->bruno, $this->carla), $this->ana))
        ->toThrow(AuthorizationException::class, 'apenas as suas próprias aprovações');

    expect(($this->count)())->toBe(1)
        ->and(Admin::audits($this->scenario, 'approval_delegation.created')[0]->data['by_administrator'])->toBeFalse();
});

it('recusa quem não aprova nem administra as regras', function (string $who): void {
    $membership = match ($who) {
        'comprador' => $this->buyer['membership'],
        'solicitante' => $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Requester),
        'equipe do distribuidor' => $this->scenario->tenant->ownerMembership,
    };
    $own = ($this->delegate)(($this->data)($this->ana, $this->bruno));

    expect(fn () => ($this->delegate)(new ApprovalDelegationData($membership->id, $this->bruno->id, $this->today->toDateString(), $this->today->toDateString(), 'Teste de acesso'), $membership))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->revoke)($own->id, $membership))->toThrow(AuthorizationException::class)
        ->and(($this->count)())->toBe(1);
})->with(['comprador', 'solicitante', 'equipe do distribuidor']);

it('valida titular, delegado, vigência e motivo', function (string $case, string $field, string $message): void {
    $data = match ($case) {
        'mesma pessoa' => ($this->data)($this->ana, $this->ana),
        'delegado sem permissão' => ($this->data)($this->ana, $this->buyer['membership']),
        'delegado revogado' => (function (): ApprovalDelegationData {
            Admin::revoke($this->scenario, $this->bruno);

            return ($this->data)($this->ana, $this->bruno);
        })(),
        'delegado de outra empresa' => ($this->data)($this->ana, $this->scenario->tenant->addBuyerUser($this->scenario->tenant->addBuyerCompany(), SystemRole::Approver)),
        'delegado de outro tenant' => (function (): ApprovalDelegationData {
            $foreign = OrderingScenario::create();

            return ($this->data)($this->ana, $foreign->tenant->addBuyerUser($foreign->tenant->addBuyerCompany(), SystemRole::Approver));
        })(),
        'titular sem permissão' => ($this->data)($this->buyer['membership'], $this->ana),
        'titular revogado' => (function (): ApprovalDelegationData {
            Admin::revoke($this->scenario, $this->carla);

            return ($this->data)($this->carla, $this->ana);
        })(),
        'começa ontem' => ($this->data)($this->ana, $this->bruno, startIn: -1),
        'começa depois de um ano' => ($this->data)($this->ana, $this->bruno, startIn: 366),
        'termina antes de começar' => new ApprovalDelegationData($this->ana->id, $this->bruno->id, $this->today->addDays(3)->toDateString(), $this->today->addDays(2)->toDateString(), 'Férias'),
        'mais de 90 dias' => ($this->data)($this->ana, $this->bruno, days: 91),
        'data inexistente' => new ApprovalDelegationData($this->ana->id, $this->bruno->id, '2026-02-30', '2026-03-02', 'Férias'),
        'último dia inválido' => new ApprovalDelegationData($this->ana->id, $this->bruno->id, $this->today->toDateString(), '02/03/2026', 'Férias'),
        'sem motivo' => ($this->data)($this->ana, $this->bruno, reason: '   '),
        'motivo curto' => ($this->data)($this->ana, $this->bruno, reason: 'abc'),
        'motivo longo' => ($this->data)($this->ana, $this->bruno, reason: str_repeat('m', 501)),
    };

    ($this->rejects)($data, $field, $message);

    expect(($this->count)())->toBe(0)
        ->and(Admin::audits($this->scenario, 'approval_delegation.created'))->toBe([]);
})->with([
    ['mesma pessoa', 'delegate', 'diferente do titular'],
    ['delegado sem permissão', 'delegate', 'O delegado precisa ser um usuário ativo'],
    ['delegado revogado', 'delegate', 'O delegado precisa ser um usuário ativo'],
    ['delegado de outra empresa', 'delegate', 'O delegado precisa ser um usuário ativo'],
    ['delegado de outro tenant', 'delegate', 'O delegado precisa ser um usuário ativo'],
    ['titular sem permissão', 'delegator', 'O titular precisa ser um usuário ativo'],
    ['titular revogado', 'delegator', 'O titular precisa ser um usuário ativo'],
    ['começa ontem', 'first_day', 'não pode começar antes de hoje'],
    ['começa depois de um ano', 'first_day', 'deve começar em até 365 dias'],
    ['termina antes de começar', 'last_day', 'não pode ser anterior ao primeiro'],
    ['mais de 90 dias', 'last_day', 'no máximo 90 dias'],
    ['data inexistente', 'first_day', 'Informe o primeiro dia'],
    ['último dia inválido', 'last_day', 'Informe o último dia'],
    ['sem motivo', 'reason', 'Informe o motivo da delegação'],
    ['motivo curto', 'reason', 'Informe o motivo da delegação'],
    ['motivo longo', 'reason', 'até 500 caracteres'],
]);

it('aceita a vigência máxima de 90 dias começando hoje', function (): void {
    $delegation = ($this->delegate)(($this->data)($this->ana, $this->bruno, days: 90));

    expect((int) $delegation->valid_from->diffInDays($delegation->valid_until))->toBe(90);
});

it('recusa sobreposição com delegação ativa do mesmo titular ao mesmo delegado', function (): void {
    $first = ($this->delegate)(($this->data)($this->ana, $this->bruno, days: 10));

    ($this->rejects)(($this->data)($this->ana, $this->bruno, startIn: 9, days: 5), 'first_day', 'Já existe delegação deste titular para o mesmo delegado');

    // Sem sobreposição, para outro delegado ou depois da revogação: aceito.
    ($this->delegate)(($this->data)($this->ana, $this->bruno, startIn: 10, days: 5));
    ($this->delegate)(($this->data)($this->ana, $this->carla, days: 10));
    ($this->revoke)($first->id);
    ($this->delegate)(($this->data)($this->ana, $this->bruno, days: 3));

    expect(($this->count)())->toBe(4);
});

it('permite ao delegado decidir em nome do titular só durante a vigência', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $delegation = ($this->delegate)(($this->data)($this->ana, $this->bruno, startIn: 1, days: 3), $this->ana);

    expect(fn () => Fx::decide($this->scenario, $this->bruno, $order))->toThrow(ApprovalNotAllowed::class, 'não é aprovador');

    $this->clock->advance('P1D');

    expect(Fx::decide($this->scenario, $this->bruno, $order))->toBe(ApprovalOutcome::Confirmed);

    $decision = $this->scenario->system(fn () => DB::table('order_approval_decisions')->where('order_id', $order->id)->first());

    expect($decision->actor_membership_id)->toBe($this->bruno->id)
        ->and($decision->principal_membership_id)->toBe($this->ana->id)
        ->and($decision->delegation_id)->toBe($delegation->id);
});

it('não deixa o delegado aprovar o pedido que ele mesmo solicitou', function (): void {
    $requester = $this->scenario->buyerWithCart(quantity: '1', sameCompany: true);
    $this->scenario->system(fn () => Membership::query()->whereKey($requester['membership']->id)
        ->update(['role_id' => $this->scenario->tenant->role(SystemRole::BuyerAdmin)->id]));
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $requester);

    ($this->delegate)(($this->data)($this->ana, $requester['membership']));

    expect(fn () => Fx::decide($this->scenario, $requester['membership'], $order))->toThrow(ApprovalNotAllowed::class, 'Quem solicitou');
});

it('revoga pelo administrador ou pelo titular, com auditoria, e o delegado deixa de decidir', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $byAdmin = ($this->delegate)(($this->data)($this->ana, $this->bruno));
    $byOwner = ($this->delegate)(($this->data)($this->ana, $this->carla, startIn: 5), $this->ana);

    expect(fn () => ($this->revoke)($byOwner->id, $this->bruno))->toThrow(AuthorizationException::class, 'Somente o titular ou o administrador');

    ($this->revoke)($byAdmin->id, reason: 'Retorno antecipado');
    ($this->revoke)($byOwner->id, $this->ana);

    $revoked = $this->scenario->system(static fn (): array => ApprovalDelegation::query()->pluck('revoked_at', 'id')->all());

    expect(array_filter($revoked))->toHaveCount(2)
        ->and(fn () => Fx::decide($this->scenario, $this->bruno, $order))->toThrow(ApprovalNotAllowed::class, 'não é aprovador')
        ->and($this->scenario->tenant->actingAs($this->bruno, fn (): array => app(ApprovalEligibility::class)->delegatorsOf($this->company->id, $this->bruno->id)))->toBe([]);

    $audits = Admin::audits($this->scenario, 'approval_delegation.revoked');

    expect($audits)->toHaveCount(2)
        ->and($audits[0]->subject_id)->toBe($byAdmin->id)
        ->and($audits[0]->reason)->toBe('Retorno antecipado')
        ->and($audits[0]->data['started'])->toBeTrue()
        ->and($audits[1]->actor_user_id)->toBe($this->ana->user_id)
        ->and($audits[1]->buyer_company_id)->toBe($this->company->id)
        ->and($audits[1]->data['started'])->toBeFalse();
});

it('recusa revogar delegação já revogada ou encerrada', function (): void {
    $active = ($this->delegate)(($this->data)($this->ana, $this->bruno));
    ($this->revoke)($active->id);
    $ended = Admin::delegation($this->scenario, $this->ana, $this->carla, $this->clock->now()->subDays(10), $this->clock->now()->subDay());

    expect(fn () => ($this->revoke)($active->id))->toThrow(ApprovalNotAllowed::class, 'já foi revogada')
        ->and(fn () => ($this->revoke)($ended->id))->toThrow(ApprovalNotAllowed::class, 'já terminou')
        ->and(fn () => ($this->revoke)($ended->id, reason: str_repeat('m', 501)))->toThrow(ApprovalNotAllowed::class, 'até 500 caracteres')
        ->and(Admin::audits($this->scenario, 'approval_delegation.revoked'))->toHaveCount(1);
});

it('trata delegação de outra empresa, de outro tenant ou malformada como inexistente', function (string $case): void {
    $id = match ($case) {
        'outra empresa' => (function (): string {
            $other = $this->scenario->tenant->addBuyerCompany();
            $from = $this->scenario->tenant->addBuyerUser($other, SystemRole::Approver);
            $to = $this->scenario->tenant->addBuyerUser($other, SystemRole::Approver);

            return Admin::delegation($this->scenario, $from, $to, $this->clock->now(), $this->clock->now()->addDays(3))->id;
        })(),
        'outro tenant' => (string) Str::uuid7(),
        'malformada' => 'delegacao-1',
    };

    expect(fn () => ($this->revoke)($id))->toThrow(ModelNotFoundException::class);
})->with(['outra empresa', 'outro tenant', 'malformada']);

it('trata titular ou delegado com identificador malformado como inexistente', function (): void {
    expect(fn () => ($this->delegate)(new ApprovalDelegationData('titular', $this->bruno->id, $this->today->toDateString(), $this->today->toDateString(), 'Férias')))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->delegate)(new ApprovalDelegationData($this->ana->id, '', $this->today->toDateString(), $this->today->toDateString(), 'Férias')))
        ->toThrow(ModelNotFoundException::class)
        ->and(($this->count)())->toBe(0);
});
