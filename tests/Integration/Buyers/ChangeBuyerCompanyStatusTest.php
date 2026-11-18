<?php

declare(strict_types=1);

use App\Actions\Buyers\ChangeBuyerCompanyStatus;
use App\Domain\Buyers\Exceptions\BuyerCompanyStatusUnchanged;
use App\Domain\Buyers\Exceptions\JustificationRequired;
use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);

    $this->change = fn (Membership $actor, BuyerCompany $company, BuyerCompanyStatus $status, string $reason, ?TenantScenario $tenant = null): BuyerCompany => ($tenant ?? $this->tenant)->actingAs(
        $actor,
        static fn (): BuyerCompany => app(ChangeBuyerCompanyStatus::class)->handle($company, $status, $reason),
    );
});

it('bloqueia a empresa e registra situação anterior, nova e justificativa na auditoria', function (): void {
    ($this->change)($this->manager, $this->company, BuyerCompanyStatus::Blocked, '  Inadimplência acima de 60 dias  ');

    expect(Fixtures::company($this->tenant, $this->company->id)->status)->toBe(BuyerCompanyStatus::Blocked);

    $logs = Fixtures::audits($this->tenant, 'buyer_company.status_changed');

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->subject_type)->toBe('buyer_company')
        ->and($logs[0]->subject_id)->toBe($this->company->id)
        ->and($logs[0]->actor_membership_id)->toBe($this->manager->id)
        ->and($logs[0]->reason)->toBe('Inadimplência acima de 60 dias')
        ->and($logs[0]->data)->toEqual(['from' => 'active', 'to' => 'blocked']);

    ($this->change)($this->manager, $this->company, BuyerCompanyStatus::Active, 'Acordo de pagamento quitado');

    expect(Fixtures::company($this->tenant, $this->company->id)->status)->toBe(BuyerCompanyStatus::Active)
        ->and(Fixtures::audits($this->tenant, 'buyer_company.status_changed'))->toHaveCount(2);
});

it('exige justificativa com ao menos 5 caracteres', function (string $reason): void {
    try {
        ($this->change)($this->manager, $this->company, BuyerCompanyStatus::Inactive, $reason);
        $this->fail('A justificativa deveria ser obrigatória.');
    } catch (JustificationRequired $e) {
        expect($e->errorCode())->toBe('justification_required')
            ->and($e->getMessage())->toBe('Informe a justificativa (mínimo de 5 caracteres).');
    }

    expect(Fixtures::company($this->tenant, $this->company->id)->status)->toBe(BuyerCompanyStatus::Active)
        ->and(Fixtures::audits($this->tenant, 'buyer_company.status_changed'))->toBeEmpty();
})->with([
    'vazia' => [''],
    'apenas espaços' => ['      '],
    'curta' => ['abcd'],
    'curta com espaços' => ['  ok  '],
]);

it('recusa justificativa acima de 500 caracteres', function (): void {
    expect(fn () => ($this->change)($this->manager, $this->company, BuyerCompanyStatus::Blocked, str_repeat('a', 501)))
        ->toThrow(JustificationRequired::class, 'A justificativa deve ter no máximo 500 caracteres.');

    expect(Fixtures::company($this->tenant, $this->company->id)->status)->toBe(BuyerCompanyStatus::Active);
});

it('recusa alteração para a situação atual', function (): void {
    expect(fn () => ($this->change)($this->manager, $this->company, BuyerCompanyStatus::Active, 'Reativação solicitada pelo comercial'))
        ->toThrow(BuyerCompanyStatusUnchanged::class, 'A empresa já está com a situação "Ativa".');

    expect(Fixtures::audits($this->tenant, 'buyer_company.status_changed'))->toBeEmpty();
});

it('exige buyers.manage para alterar a situação', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);

    expect(fn () => ($this->change)($actor, $this->company, BuyerCompanyStatus::Blocked, 'Bloqueio sem permissão de gestão'))
        ->toThrow(AuthorizationException::class);

    expect(Fixtures::company($this->tenant, $this->company->id)->status)->toBe(BuyerCompanyStatus::Active);
})->with([
    'representante comercial' => [SystemRole::SalesRep],
    'financeiro' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);

it('não altera a situação de empresa de outro distribuidor', function (): void {
    $other = TenantScenario::create();
    $foreign = $other->addBuyerCompany();

    expect(fn () => ($this->change)($this->manager, $foreign, BuyerCompanyStatus::Blocked, 'Identificador de outro distribuidor'))
        ->toThrow(ModelNotFoundException::class);

    expect(Fixtures::company($other, $foreign->id)->status)->toBe(BuyerCompanyStatus::Active)
        ->and(Fixtures::audits($other, 'buyer_company.status_changed'))->toBeEmpty();
});
