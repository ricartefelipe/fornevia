<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\Branch;
use App\Models\Identity\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Builders\TenantScenario;

/*
| A requisição autenticada inteira roda em uma transação com o contexto do
| tenant. Uma resposta originada de exceção (erro interno, validação,
| autorização) desfaz tudo o que a requisição gravou; a resposta de erro
| renderizada é preservada.
*/

beforeEach(function (): void {
    Route::middleware(['web', 'auth', 'context:staff'])->prefix('/teste/filiais')->group(function (): void {
        Route::post('/sucesso', static function (): JsonResponse {
            $branch = new Branch(['code' => 'CPS01', 'name' => 'Filial Campinas', 'city' => 'Campinas', 'state' => 'SP']);
            $branch->save();

            return response()->json(['id' => $branch->id], 201);
        });

        Route::post('/falha', static function (): never {
            (new Branch(['code' => 'CPS01', 'name' => 'Filial Campinas', 'city' => 'Campinas', 'state' => 'SP']))->save();

            throw new RuntimeException('Falha na integração fiscal após gravar a filial.');
        });

        Route::post('/validacao', static function (Request $request): JsonResponse {
            (new Branch(['code' => 'CPS01', 'name' => 'Filial Campinas', 'city' => 'Campinas', 'state' => 'SP']))->save();

            $request->validate(['inscricao_estadual' => ['required', 'digits_between:8,14']]);

            return response()->json([], 201);
        });

        Route::post('/proibido', static function (): never {
            (new Branch(['code' => 'CPS01', 'name' => 'Filial Campinas', 'city' => 'Campinas', 'state' => 'SP']))->save();

            abort(403, 'Operação não permitida para este perfil.');
        });
    });

    $this->user = User::factory()->create()->refresh();
    $this->tenant = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $this->staff = $this->tenant->addStaff(SystemRole::Viewer, $this->user);
    $this->branchExists = fn (): bool => $this->tenant->system(
        static fn (): bool => Branch::query()->where('code', 'CPS01')->exists(),
    );
});

it('confirma a gravação feita no contexto do tenant quando a requisição termina com sucesso', function (): void {
    $response = $this->actingAs($this->user)
        ->withSession(TenantScenario::sessionFor($this->staff))
        ->postJson('/teste/filiais/sucesso');

    $response->assertCreated();

    $stored = $this->tenant->system(static fn (): ?Branch => Branch::query()->where('code', 'CPS01')->first());

    expect($stored)->not->toBeNull()
        ->and($stored?->id)->toBe($response->json('id'))
        ->and($stored?->tenant_id)->toBe($this->tenant->organization->id)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('desfaz a gravação e preserva a resposta de erro quando a rota lança exceção', function (): void {
    $this->actingAs($this->user)
        ->withSession(TenantScenario::sessionFor($this->staff))
        ->post('/teste/filiais/falha')
        ->assertInternalServerError();

    expect(($this->branchExists)())->toBeFalse()
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('desfaz a gravação quando a requisição falha na validação', function (): void {
    $this->actingAs($this->user)
        ->withSession(TenantScenario::sessionFor($this->staff))
        ->postJson('/teste/filiais/validacao', ['inscricao_estadual' => 'isento'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('inscricao_estadual');

    expect(($this->branchExists)())->toBeFalse();
});

it('desfaz a gravação quando a requisição é interrompida por falta de autorização', function (): void {
    $this->actingAs($this->user)
        ->withSession(TenantScenario::sessionFor($this->staff))
        ->post('/teste/filiais/proibido')
        ->assertForbidden();

    expect(($this->branchExists)())->toBeFalse();
});
