<?php

declare(strict_types=1);

use App\Actions\Identity\RevokeMembership;
use App\Domain\Identity\Exceptions\LastOwnerRequired;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Identity\Membership;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->clock = new FrozenClock(CarbonImmutable::now('UTC')->startOfSecond());
    app()->instance(Clock::class, $this->clock);

    $this->tenant = TenantScenario::create();

    // Revoga como a associação informada, recarregando o alvo no escopo do tenant.
    $this->revoke = fn (Membership $actor, Membership $target, string $reason): Membership => $this->tenant->actingAs(
        $actor,
        static fn (): Membership => app(RevokeMembership::class)->handle(Membership::query()->findOrFail($target->id), $reason),
    );

    $this->stored = fn (Membership $membership): Membership => $this->tenant->system(
        static fn (): Membership => Membership::query()->findOrFail($membership->id),
    );

    $this->revocationLogs = fn (Membership $membership) => $this->tenant->system(
        static fn () => AuditLog::query()
            ->where('action', 'membership.revoked')
            ->where('subject_id', $membership->id)
            ->get(),
    );
});

it('revoga o acesso registrando autor, data, justificativa e auditoria', function (): void {
    $admin = $this->tenant->addStaff(SystemRole::Admin);
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    $result = ($this->revoke)($admin, $seller, '  Desligamento do representante da região sul  ');

    $stored = ($this->stored)($seller);

    expect($result->status)->toBe(MembershipStatus::Revoked)
        ->and($stored->status)->toBe(MembershipStatus::Revoked)
        ->and($stored->revoked_at?->equalTo($this->clock->now()))->toBeTrue()
        ->and($stored->revoked_by_user_id)->toBe($admin->user_id)
        ->and($stored->revoke_reason)->toBe('Desligamento do representante da região sul');

    $logs = ($this->revocationLogs)($seller);

    expect($logs)->toHaveCount(1);

    $log = $logs->first();

    expect($log->subject_type)->toBe('membership')
        ->and($log->actor_user_id)->toBe($admin->user_id)
        ->and($log->actor_membership_id)->toBe($admin->id)
        ->and($log->reason)->toBe('Desligamento do representante da região sul')
        ->and($log->data)->toEqual([
            'user_id' => $seller->user_id,
            'kind' => 'staff',
            'role' => SystemRole::SalesRep->value,
        ]);
});

it('exige justificativa com ao menos cinco caracteres significativos', function (string $reason): void {
    $admin = $this->tenant->addStaff(SystemRole::Admin);
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    expect(fn () => ($this->revoke)($admin, $seller, $reason))
        ->toThrow(InvalidArgumentException::class, 'Informe a justificativa da revogação.');

    $stored = ($this->stored)($seller);

    expect($stored->status)->toBe(MembershipStatus::Active)
        ->and($stored->revoked_at)->toBeNull()
        ->and($stored->revoke_reason)->toBeNull()
        ->and(($this->revocationLogs)($seller))->toBeEmpty();
})->with([
    'vazia' => [''],
    'apenas espaços' => ['        '],
    'curta após remover espaços' => ['   Sair   '],
]);

it('trata a revogação de um acesso já revogado como operação sem efeito', function (): void {
    $admin = $this->tenant->addStaff(SystemRole::Admin);
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    ($this->revoke)($admin, $seller, 'Encerramento do contrato de representação');
    $firstRevokedAt = $this->clock->now();

    $this->clock->advance('2 hours');

    $again = ($this->revoke)($this->tenant->ownerMembership, $seller, 'Nova tentativa com outro motivo');

    $stored = ($this->stored)($seller);

    expect($again->status)->toBe(MembershipStatus::Revoked)
        ->and($again->revoke_reason)->toBe('Encerramento do contrato de representação')
        ->and($stored->revoked_at?->equalTo($firstRevokedAt))->toBeTrue()
        ->and($stored->revoked_by_user_id)->toBe($admin->user_id)
        ->and($stored->revoke_reason)->toBe('Encerramento do contrato de representação')
        ->and(($this->revocationLogs)($seller))->toHaveCount(1);
});

it('permite revogar um proprietário enquanto restar outro ativo e bloqueia a revogação do último', function (): void {
    $owner = $this->tenant->ownerMembership;
    $partner = $this->tenant->addStaff(SystemRole::Owner);

    ($this->revoke)($owner, $partner, 'Saída do sócio da sociedade');

    expect(($this->stored)($partner)->status)->toBe(MembershipStatus::Revoked)
        ->and(($this->revocationLogs)($partner)->first()?->data['role'])->toBe(SystemRole::Owner->value);

    // O proprietário revogado não conta: o restante é o último ativo.
    expect(fn () => ($this->revoke)($owner, $owner, 'Encerramento das atividades'))
        ->toThrow(LastOwnerRequired::class);

    $stored = ($this->stored)($owner);

    expect($stored->status)->toBe(MembershipStatus::Active)
        ->and($stored->revoked_at)->toBeNull()
        ->and(($this->revocationLogs)($owner))->toBeEmpty();
});

it('impede que administrador revogue proprietário, mesmo havendo outro proprietário ativo', function (): void {
    $admin = $this->tenant->addStaff(SystemRole::Admin);
    $partner = $this->tenant->addStaff(SystemRole::Owner);

    expect(fn () => ($this->revoke)($admin, $partner, 'Tentativa de remover o sócio'))
        ->toThrow(AuthorizationException::class, 'Somente proprietários');

    expect(($this->stored)($partner)->status)->toBe(MembershipStatus::Active)
        ->and(($this->stored)($this->tenant->ownerMembership)->status)->toBe(MembershipStatus::Active)
        ->and(($this->revocationLogs)($partner))->toBeEmpty();
});

it('exige members.manage para revogar acesso da equipe', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    expect(fn () => ($this->revoke)($actor, $seller, 'Revogação sem permissão adequada'))
        ->toThrow(AuthorizationException::class);

    expect(($this->stored)($seller)->status)->toBe(MembershipStatus::Active)
        ->and(($this->revocationLogs)($seller))->toBeEmpty();
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'financeiro' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);
