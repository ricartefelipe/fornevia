<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Buyers\BuyerHistory;
use App\Models\Audit\AuditLog;
use App\Services\Audit\AuditRecorder;
use Livewire\Livewire;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Integration\Pricing\PricingFixtures as Pricing;
use Tests\Support\Builders\TenantScenario;

/*
| Histórico da empresa compradora: rótulos em português para acessos e
| condições negociadas; condições só aparecem para quem consulta preços.
*/

beforeEach(function (): void {
    Pricing::freezeClock();
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->finance = $this->tenant->addStaff(SystemRole::Finance);
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);
    $buyer = $this->tenant->addBuyerUser($this->company);

    $this->tenant->actingAs($this->tenant->ownerMembership, static fn (): AuditLog => app(AuditRecorder::class)->record('membership.granted', $buyer));
    $sku = Pricing::sku($this->tenant, 'CIM-50', 'Cimento CP II 50 kg', 'PC');
    Pricing::negotiated($this->tenant, $this->company, $sku, '36,90', '2026-11-01');
});

it('exibe rótulos de acesso concedido e de condição negociada', function (): void {
    Fixtures::livewireAs($this->tenant, $this->manager, fn () => Livewire::test(BuyerHistory::class, ['buyerId' => $this->company->id])
        ->assertSee('Acesso concedido')
        ->assertSee('Condição negociada registrada')
        ->assertDontSee('membership.granted')
        ->assertDontSee('negotiated_price.created'));
});

it('oculta as condições negociadas de quem não consulta preços', function (): void {
    Fixtures::livewireAs($this->tenant, $this->finance, fn () => Livewire::test(BuyerHistory::class, ['buyerId' => $this->company->id])
        ->assertSee('Acesso concedido')
        ->assertDontSee('Condição negociada registrada'));
});
