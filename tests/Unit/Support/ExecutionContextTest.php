<?php

declare(strict_types=1);

use App\Support\Tenancy\ContextKind;
use App\Support\Tenancy\Exceptions\InvalidExecutionContext;
use App\Support\Tenancy\ExecutionContext;

const UUID_A = '0193a1f0-0000-7000-8000-000000000001';
const UUID_B = '0193a1f0-0000-7000-8000-000000000002';
const UUID_C = '0193a1f0-0000-7000-8000-000000000003';
const UUID_D = '0193a1f0-0000-7000-8000-000000000004';

it('gera as configurações locais do banco para o contexto de comprador', function (): void {
    $context = ExecutionContext::buyer(UUID_A, UUID_B, UUID_C, UUID_D);

    expect($context->databaseSettings())->toBe([
        'app.context_kind' => 'buyer',
        'app.user_id' => UUID_A,
        'app.tenant_id' => UUID_B,
        'app.buyer_company_id' => UUID_C,
    ])->and($context->kind->seesAllBuyers())->toBeFalse();
});

it('não restringe empresa compradora no contexto da equipe do distribuidor', function (): void {
    $context = ExecutionContext::staff(UUID_A, UUID_B, UUID_D);

    expect($context->databaseSettings()['app.buyer_company_id'])->toBe('')
        ->and($context->kind)->toBe(ContextKind::Staff)
        ->and($context->kind->seesAllBuyers())->toBeTrue();
});

it('contexto apenas de usuário não possui tenant', function (): void {
    $context = ExecutionContext::user(UUID_A);

    expect($context->tenantId)->toBeNull()
        ->and($context->kind->hasTenant())->toBeFalse();

    $context->requireTenantId();
})->throws(InvalidExecutionContext::class);

it('rejeita identificadores que não sejam UUID', function (): void {
    ExecutionContext::staff(UUID_A, "x' or 1=1 --", UUID_D);
})->throws(InvalidExecutionContext::class);

it('compara contextos por todos os componentes', function (): void {
    $a = ExecutionContext::system(UUID_B);
    $b = ExecutionContext::system(UUID_B);
    $c = ExecutionContext::system(UUID_C);
    $d = ExecutionContext::integration(UUID_B, UUID_D);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse()
        ->and($a->equals($d))->toBeFalse()
        ->and($d->toLogContext())->toBe(['context_kind' => 'integration', 'tenant_id' => UUID_B, 'api_client_id' => UUID_D]);
});
