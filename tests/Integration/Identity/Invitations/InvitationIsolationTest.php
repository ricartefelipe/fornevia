<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Integration\Identity\Invitations\InvitationFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

/*
 * Isolamento dos convites no banco (RLS), verificado com SQL direto, sem
 * o filtro do Eloquent: o banco é a garantia definitiva.
 */

beforeEach(function (): void {
    Notification::fake();
});

it('isola os convites de cada tenant mesmo em SQL direto', function (): void {
    $tenantA = TenantScenario::create();
    $tenantB = TenantScenario::create();
    $fromA = Fixtures::issue($tenantA, $tenantA->ownerMembership, 'pessoa.a@example.com', SystemRole::Viewer);
    $fromB = Fixtures::issue($tenantB, $tenantB->ownerMembership, 'pessoa.b@example.com', SystemRole::Viewer);

    $seenByA = $tenantA->system(fn (): array => DB::table('invitations')->pluck('id')->all());
    $seenByB = $tenantB->system(fn (): array => DB::table('invitations')->pluck('id')->all());
    $withoutContext = DB::transaction(fn (): array => DB::table('invitations')->pluck('id')->all());

    expect($seenByA)->toBe([$fromA->invitation->id])
        ->and($seenByB)->toBe([$fromB->invitation->id])
        ->and($withoutContext)->toBe([]);
});

it('expõe ao contexto de comprador somente os convites da própria empresa', function (): void {
    $tenant = TenantScenario::create();
    $company = $tenant->addBuyerCompany();
    $otherCompany = $tenant->addBuyerCompany();
    $buyerAdmin = $tenant->addBuyerUser($company, SystemRole::BuyerAdmin);
    $salesRep = $tenant->addStaff(SystemRole::SalesRep);

    $own = Fixtures::issue($tenant, $buyerAdmin, 'colega@ferragensnorte.example.com', SystemRole::Requester, $company);
    $foreign = Fixtures::issue($tenant, $tenant->ownerMembership, 'compras@outraempresa.example.com', SystemRole::Purchaser, $otherCompany);
    $staff = Fixtures::issue($tenant, $tenant->ownerMembership, 'nova.gerente@example.com', SystemRole::SalesManager);

    $seenByBuyer = $tenant->actingAs($buyerAdmin, fn (): array => DB::table('invitations')->pluck('email', 'id')->all());
    $seenByStaff = $tenant->actingAs($salesRep, fn (): array => DB::table('invitations')->pluck('id')->all());

    // Convites da equipe do distribuidor (e-mails, papéis, hash do token)
    // não pertencem à empresa compradora.
    expect($seenByBuyer)->toBe([$own->invitation->id => 'colega@ferragensnorte.example.com'])
        ->and($seenByStaff)->toEqualCanonicalizing([$own->invitation->id, $foreign->invitation->id, $staff->invitation->id]);
});
