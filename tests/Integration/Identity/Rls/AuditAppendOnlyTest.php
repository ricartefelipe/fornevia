<?php

declare(strict_types=1);

use App\Services\Audit\AuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| audit_logs (por tenant, com RLS) e platform_audit_logs (plataforma) são
| append-only para o papel da aplicação: INSERT e SELECT permitidos;
| UPDATE, DELETE e TRUNCATE revogados no banco.
*/

it('registra e lê a auditoria do tenant, isolada dos demais tenants', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();

    $aurora->actingAs($aurora->ownerMembership, fn () => app(AuditRecorder::class)->record(
        'buyer_company.credit_reviewed',
        null,
        ['limite_cents' => 2_500_000],
        'Revisão trimestral de crédito',
    ));

    $auroraActions = $aurora->system(fn (): array => DB::table('audit_logs')->orderBy('created_at')->orderBy('id')->pluck('action')->all());
    $borealActions = $boreal->system(fn (): array => DB::table('audit_logs')->pluck('tenant_id')->unique()->values()->all());
    $actor = $aurora->system(fn () => DB::table('audit_logs')->where('action', 'buyer_company.credit_reviewed')->first());

    expect($auroraActions)->toEqualCanonicalizing(['organization.created', 'buyer_company.credit_reviewed'])
        ->and($borealActions)->toBe([$boreal->organization->id])
        ->and($actor->actor_membership_id)->toBe($aurora->ownerMembership->id)
        ->and($actor->actor_user_id)->toBe($aurora->owner->id);
});

it('recusa alterar ou apagar a auditoria do tenant por falta de privilégio', function (string $context, string $operation): void {
    $tenant = TenantScenario::create();

    $statement = match ($operation) {
        'UPDATE' => fn () => DB::table('audit_logs')->update(['action' => 'organization.deleted', 'reason' => 'Ajuste']),
        'DELETE' => fn () => DB::table('audit_logs')->delete(),
        'TRUNCATE' => fn () => DB::statement('truncate table audit_logs'),
    };

    $run = match ($context) {
        'sistema' => fn () => $tenant->system($statement),
        'proprietário' => fn () => $tenant->actingAs($tenant->ownerMembership, $statement),
    };

    expect($run)->toThrow(QueryException::class, 'permission denied for table audit_logs');

    expect($tenant->system(fn (): array => DB::table('audit_logs')->pluck('action')->all()))
        ->toBe(['organization.created']);
})->with(['sistema', 'proprietário'])->with(['UPDATE', 'DELETE', 'TRUNCATE']);

it('recusa alterar ou apagar a auditoria da plataforma por falta de privilégio', function (string $operation): void {
    $tenant = TenantScenario::create();

    $statement = match ($operation) {
        'UPDATE' => fn () => DB::table('platform_audit_logs')->update(['action' => 'organization.deleted']),
        'DELETE' => fn () => DB::table('platform_audit_logs')->where('organization_id', $tenant->organization->id)->delete(),
        'TRUNCATE' => fn () => DB::statement('truncate table platform_audit_logs'),
    };

    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class, 'permission denied for table platform_audit_logs');

    expect(DB::table('platform_audit_logs')->where('organization_id', $tenant->organization->id)->pluck('action')->all())
        ->toBe(['organization.created']);
})->with(['UPDATE', 'DELETE', 'TRUNCATE']);

it('permite à aplicação acrescentar e consultar a auditoria da plataforma', function (): void {
    $tenant = TenantScenario::create();
    $id = (string) Str::uuid7();

    DB::table('platform_audit_logs')->insert([
        'id' => $id,
        'actor_user_id' => $tenant->owner->id,
        'organization_id' => $tenant->organization->id,
        'action' => 'organization.status_changed',
        'data' => json_encode(['from' => 'trial', 'to' => 'active']),
        'reason' => 'Contratação confirmada',
        'created_at' => now(),
    ]);

    expect(DB::table('platform_audit_logs')->where('organization_id', $tenant->organization->id)->orderBy('created_at')->pluck('action')->all())
        ->toBe(['organization.created', 'organization.status_changed']);
});
