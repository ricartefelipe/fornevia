<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\Branch;
use App\Models\Identity\User;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\Exceptions\TenantContextConflict;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| TenantDatabase: uma unidade de trabalho iniciada por um usuário pode
| entrar, em savepoint, em outro contexto do MESMO usuário (seleção de
| contexto, aceite de convite, onboarding) e volta ao contexto anterior ao
| final. Contextos sem usuário e contextos de outro usuário são recusados
| com TenantContextConflict.
*/

beforeEach(function (): void {
    $this->tenancy = app(TenantDatabase::class);
    $this->current = app(CurrentContext::class);

    $this->user = User::factory()->create(['name' => 'Renata Albuquerque']);
    $this->tenant = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $this->other = TenantScenario::create(name: 'Atacado Lagoa Funda');
    $this->staff = $this->tenant->addStaff(SystemRole::Warehouse, $this->user);

    $this->userContext = ExecutionContext::user($this->user->id);
    $this->userSettings = [
        'context_kind' => 'user',
        'user_id' => $this->user->id,
        'tenant_id' => '',
        'buyer_company_id' => '',
    ];

    // Estado observável da conexão e do contexto da aplicação.
    $this->snapshot = fn (): array => [
        'settings' => $this->tenancy->activeDatabaseSettings(),
        'context' => $this->current->get(),
        'visible_branches' => DB::table('branches')->orderBy('code')->pluck('code')->all(),
    ];
});

it('eleva o contexto de usuário para a associação do próprio usuário e restaura as configurações ao final', function (string $kind): void {
    if ($kind === 'equipe') {
        $membership = $this->staff;
        $expected = ['context_kind' => 'staff', 'user_id' => $this->user->id, 'tenant_id' => $this->tenant->organization->id, 'buyer_company_id' => ''];
        $visible = ['MATRIZ'];
    } else {
        $company = $this->tenant->addBuyerCompany(['trade_name' => 'Mercado Bom Preço']);
        $membership = $this->tenant->addBuyerUser($company, SystemRole::Purchaser, $this->user);
        $expected = ['context_kind' => 'buyer', 'user_id' => $this->user->id, 'tenant_id' => $this->tenant->organization->id, 'buyer_company_id' => $company->id];
        $visible = ['MATRIZ'];
    }

    [$before, $inside, $after] = inContext($this->userContext, fn (): array => [
        ($this->snapshot)(),
        inContext($membership->executionContext(), $this->snapshot),
        ($this->snapshot)(),
    ]);

    expect($before['settings'])->toBe($this->userSettings)
        ->and($before['context']->equals($this->userContext))->toBeTrue()
        ->and($before['visible_branches'])->toBe([])
        ->and($inside['settings'])->toBe($expected)
        ->and($inside['context']->equals($membership->executionContext()))->toBeTrue()
        ->and($inside['visible_branches'])->toBe($visible)
        ->and($after['settings'])->toBe($this->userSettings)
        ->and($after['context']->equals($this->userContext))->toBeTrue()
        ->and($after['visible_branches'])->toBe([])
        ->and($this->current->get())->toBeNull()
        ->and(DB::connection()->transactionLevel())->toBe(0);
})->with(['equipe', 'comprador']);

it('confirma as gravações da elevação junto com a transação externa', function (): void {
    inContext($this->userContext, function (): void {
        inContext($this->staff->executionContext(), static fn () => Branch::factory()->create(['code' => 'CPS01', 'name' => 'Filial Campinas']));
        DB::table('users')->where('id', $this->user->id)->update(['name' => 'Renata Albuquerque Lins']);
    });

    expect($this->tenant->system(static fn (): array => Branch::query()->orderBy('code')->pluck('code')->all()))->toBe(['CPS01', 'MATRIZ'])
        ->and($this->user->fresh()?->name)->toBe('Renata Albuquerque Lins');
});

it('desfaz as gravações da elevação quando a transação externa falha depois dela', function (): void {
    try {
        inContext($this->userContext, function (): never {
            inContext($this->staff->executionContext(), static fn () => Branch::factory()->create(['code' => 'CPS01', 'name' => 'Filial Campinas']));

            throw new RuntimeException('Falha ao concluir o aceite do convite.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Falha ao concluir o aceite do convite.');
    }

    expect($this->tenant->system(static fn (): array => Branch::query()->pluck('code')->all()))->toBe(['MATRIZ'])
        ->and($this->current->get())->toBeNull();
});

it('desfaz a gravação e mantém o contexto anterior quando a elevação falha', function (string $failure): void {
    [$caught, $after] = inContext($this->userContext, function () use ($failure): array {
        DB::table('users')->where('id', $this->user->id)->update(['name' => 'Renata Albuquerque Lins']);

        try {
            inContext($this->staff->executionContext(), static function () use ($failure): never {
                Branch::factory()->create(['code' => 'CPS01', 'name' => 'Filial Campinas']);

                if ($failure === 'erro do banco de dados') {
                    // Código já usado pela matriz: viola unique (tenant_id, code).
                    Branch::factory()->create(['code' => 'MATRIZ', 'name' => 'Matriz duplicada']);
                }

                throw new RuntimeException('Falha na validação fiscal da filial.');
            });
            $caught = null;
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        return [$caught, ($this->snapshot)()];
    });

    expect($caught)->toBeInstanceOf($failure === 'erro do banco de dados' ? QueryException::class : RuntimeException::class)
        ->and($after['settings'])->toBe($this->userSettings)
        ->and($after['context']->equals($this->userContext))->toBeTrue()
        ->and($after['visible_branches'])->toBe([])
        ->and($this->tenant->system(static fn (): array => Branch::query()->pluck('code')->all()))->toBe(['MATRIZ'])
        // A transação externa segue utilizável e é confirmada normalmente.
        ->and($this->user->fresh()?->name)->toBe('Renata Albuquerque Lins');
})->with(['exceção da aplicação', 'erro do banco de dados']);

it('recusa elevar o contexto de usuário para um contexto que não pertence ao mesmo usuário', function (string $case): void {
    $requested = match ($case) {
        'associação de outro usuário' => $this->tenant->ownerMembership->executionContext(),
        'sistema sem usuário' => ExecutionContext::system($this->tenant->organization->id),
        'sistema em nome de outro usuário' => ExecutionContext::system($this->tenant->organization->id, $this->tenant->owner->id),
        'usuário diferente' => ExecutionContext::user($this->tenant->owner->id),
        'integração' => ExecutionContext::integration($this->tenant->organization->id, (string) Str::uuid7()),
    };

    [$caught, $after] = inContext($this->userContext, function () use ($requested): array {
        try {
            inContext($requested, static fn () => Branch::factory()->create(['code' => 'CPS01', 'name' => 'Filial Campinas']));
            $caught = null;
        } catch (TenantContextConflict $exception) {
            $caught = $exception;
        }

        return [$caught, ($this->snapshot)()];
    });

    expect($caught)->toBeInstanceOf(TenantContextConflict::class)
        ->and($after['settings'])->toBe($this->userSettings)
        ->and($after['context']->equals($this->userContext))->toBeTrue()
        ->and($this->tenant->system(static fn (): array => Branch::query()->pluck('code')->all()))->toBe(['MATRIZ']);
})->with([
    'associação de outro usuário',
    'sistema sem usuário',
    'sistema em nome de outro usuário',
    'usuário diferente',
    'integração',
]);

it('recusa que um contexto de equipe aninhe contexto sem o mesmo usuário', function (string $case): void {
    $requested = match ($case) {
        'sistema de outro tenant' => ExecutionContext::system($this->other->organization->id),
        'sistema do mesmo tenant' => ExecutionContext::system($this->tenant->organization->id),
        'associação de outro usuário' => $this->other->ownerMembership->executionContext(),
    };
    $staffSettings = ['context_kind' => 'staff', 'user_id' => $this->user->id, 'tenant_id' => $this->tenant->organization->id, 'buyer_company_id' => ''];

    [$caught, $after] = inContext($this->staff->executionContext(), function () use ($requested): array {
        try {
            inContext($requested, static fn () => DB::table('branches')->pluck('code')->all());
            $caught = null;
        } catch (TenantContextConflict $exception) {
            $caught = $exception;
        }

        return [$caught, ($this->snapshot)()];
    });

    expect($caught)->toBeInstanceOf(TenantContextConflict::class)
        ->and($after['settings'])->toBe($staffSettings)
        ->and($after['context']->equals($this->staff->executionContext()))->toBeTrue()
        ->and($after['visible_branches'])->toBe(['MATRIZ']);
})->with([
    'sistema de outro tenant',
    'sistema do mesmo tenant',
    'associação de outro usuário',
]);

it('eleva um contexto de equipe para outro contexto do mesmo usuário e restaura o contexto de equipe', function (string $case): void {
    $otherStaff = $this->other->addStaff(SystemRole::Admin, $this->user);
    [$requested, $expectedInside] = match ($case) {
        'sistema de outro tenant em nome do mesmo usuário' => [ExecutionContext::system($this->other->organization->id, $this->user->id), ['MATRIZ']],
        'associação do mesmo usuário em outro tenant' => [$otherStaff->executionContext(), ['MATRIZ']],
        'contexto apenas de usuário' => [$this->userContext, []],
    };
    $staffSettings = ['context_kind' => 'staff', 'user_id' => $this->user->id, 'tenant_id' => $this->tenant->organization->id, 'buyer_company_id' => ''];

    [$inside, $after] = inContext($this->staff->executionContext(), function () use ($requested): array {
        $inside = inContext($requested, fn (): array => ($this->snapshot)());

        return [$inside, ($this->snapshot)()];
    });

    expect($inside['context']->equals($requested))->toBeTrue()
        ->and($inside['settings']['tenant_id'])->toBe($requested->tenantId ?? '')
        ->and($inside['visible_branches'])->toBe($expectedInside)
        ->and($after['settings'])->toBe($staffSettings)
        ->and($after['context']->equals($this->staff->executionContext()))->toBeTrue()
        ->and($after['visible_branches'])->toBe(['MATRIZ']);
})->with([
    'sistema de outro tenant em nome do mesmo usuário',
    'associação do mesmo usuário em outro tenant',
    'contexto apenas de usuário',
]);

it('restaura cada nível em elevações aninhadas do mesmo usuário', function (): void {
    $otherStaff = $this->other->addStaff(SystemRole::Admin, $this->user);

    [$inside, $middle, $after] = inContext($this->userContext, function () use ($otherStaff): array {
        return [...inContext($this->staff->executionContext(), function () use ($otherStaff): array {
            $inside = inContext($otherStaff->executionContext(), fn (): array => ($this->snapshot)());

            return [$inside, ($this->snapshot)()];
        }), ($this->snapshot)()];
    });

    expect($inside['settings']['tenant_id'])->toBe($this->other->organization->id)
        ->and($middle['settings']['tenant_id'])->toBe($this->tenant->organization->id)
        ->and($middle['context']->equals($this->staff->executionContext()))->toBeTrue()
        ->and($after['settings'])->toBe($this->userSettings)
        ->and($after['context']->equals($this->userContext))->toBeTrue();
});

it('encerra a transação sem resíduo de contexto na conexão após a elevação', function (): void {
    inContext($this->userContext, fn () => inContext($this->staff->executionContext(), static fn () => Branch::query()->count()));

    $settings = DB::transaction(fn (): array => $this->tenancy->activeDatabaseSettings());

    expect($settings)->toBe(['context_kind' => '', 'user_id' => '', 'tenant_id' => '', 'buyer_company_id' => ''])
        ->and($this->current->get())->toBeNull();
});
