<?php

declare(strict_types=1);

use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Organization;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use App\Services\Demo\DemoData;
use Illuminate\Support\Facades\Hash;

/*
| Dados de demonstração: dois distribuidores isolados, com equipe,
| catálogo, compradores e pedidos feitos pelo fluxo de compra; recusados em
| produção, com senha curta ou quando já existem.
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
});

it('cria dois distribuidores isolados com pedidos feitos pelo fluxo de compra', function (): void {
    $this->artisan('demo:seed', ['--password' => 'senha-demo-segura'])
        ->expectsOutputToContain('Distribuidora Pedra Branca')
        ->expectsOutputToContain('compras@serraazul.example')
        ->assertSuccessful();

    $organizations = Organization::query()->orderBy('trade_name')->get();
    expect($organizations->pluck('trade_name')->all())->toBe(['Distribuidora Pedra Branca', 'Elétrica Horizonte']);

    foreach ($organizations as $organization) {
        [$companies, $orders] = asTenant($organization, static fn (): array => [
            BuyerCompany::query()->orderBy('trade_name')->pluck('trade_name')->all(),
            Order::query()->get(),
        ]);

        expect($companies)->toHaveCount(2)
            ->and($orders)->toHaveCount(2)
            ->and($orders->every(static fn (Order $o): bool => $o->total_cents->isPositive()))->toBeTrue();
    }

    // Cada distribuidor só enxerga os próprios compradores.
    expect(asTenant($organizations[0], static fn (): array => BuyerCompany::query()->pluck('trade_name')->all()))
        ->toContain('Mercado Bom Preço')->not->toContain('Hospital Vida Plena');

    $user = User::query()->where('email', 'dono@pedrabranca.example')->sole();
    expect(Hash::check('senha-demo-segura', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(User::query()->where('email', 'like', '%.example')->count())->toBe(2 * (5 + 4));
});

it('recusa senha curta, nova execução e produção', function (): void {
    $this->artisan('demo:seed', ['--password' => 'curta'])->expectsOutputToContain('ao menos 12 caracteres')->assertFailed();

    $this->artisan('demo:seed', ['--password' => 'senha-demo-segura'])->assertSuccessful();
    $this->artisan('demo:seed', ['--password' => 'senha-demo-segura'])->expectsOutputToContain('já foram criados')->assertFailed();

    app()->detectEnvironment(static fn (): string => 'production');
    expect(fn () => app(DemoData::class)->seed('senha-demo-segura'))->toThrow(RuntimeException::class, 'produção');
});
