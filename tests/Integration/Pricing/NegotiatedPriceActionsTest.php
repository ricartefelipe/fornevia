<?php

declare(strict_types=1);

use App\Actions\Pricing\RevokeNegotiatedPrice;
use App\Actions\Pricing\SaveNegotiatedPrice;
use App\Domain\Pricing\Exceptions\InvalidUnitPrice;
use App\Domain\Pricing\Exceptions\InvalidValidityPeriod;
use App\Domain\Pricing\Exceptions\JustificationRequired;
use App\Domain\Pricing\Exceptions\NegotiatedPriceNotActive;
use App\Domain\Pricing\Exceptions\NegotiatedPriceOverlap;
use App\Domain\Pricing\Exceptions\SkuNotPriceable;
use App\Domain\Shared\ConflictingState;
use App\Domain\Shared\Money;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\NegotiatedPriceStatus;
use App\Models\Audit\AuditLog;
use App\Models\Pricing\NegotiatedPrice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->company = Fx::company($this->tenant, 'Supermercado Vila Nova');
    $this->sku = Fx::sku($this->tenant, 'LEI-1L', 'Leite UHT integral 1 L');

    $this->stored = fn (): array => $this->tenant->system(static fn (): array => NegotiatedPrice::query()
        ->orderBy('valid_from')
        ->get()
        ->map(static fn (NegotiatedPrice $p): array => [
            $p->unit_price_cents->cents,
            $p->valid_from->toDateString(),
            $p->valid_until?->toDateString(),
            $p->status->value,
        ])
        ->all());
});

it('registra a condição com o usuário atual como aprovador, auditoria e nova versão de preços', function (): void {
    $version = Fx::version($this->tenant);

    $price = Fx::negotiated($this->tenant, $this->company, $this->sku, '4,39', '2026-11-20', '2027-02-28', $this->manager, '  Contrato trimestral  ');
    $stored = $this->tenant->system(static fn (): NegotiatedPrice => NegotiatedPrice::query()->findOrFail($price->id));
    $log = $this->tenant->system(static fn (): AuditLog => AuditLog::query()->where('action', 'negotiated_price.created')->sole());

    expect($stored->approved_by_user_id)->toBe($this->manager->user_id)
        ->and($stored->status)->toBe(NegotiatedPriceStatus::Active)
        ->and($stored->source)->toBe('manual')
        ->and($stored->notes)->toBe('Contrato trimestral')
        ->and($stored->unit_price_cents->cents)->toBe(439)
        ->and($log->subject_id)->toBe($price->id)
        ->and($log->actor_user_id)->toBe($this->manager->user_id)
        ->and($log->data['sku_code'])->toBe('LEI-1L')
        ->and($log->data['valid_until'])->toBe('2027-02-28')
        ->and(Fx::version($this->tenant))->toBe($version + 1);
});

it('recusa sobreposição de vigência ativa com mensagem que identifica a condição existente', function (string $from, ?string $until): void {
    Fx::negotiated($this->tenant, $this->company, $this->sku, '4,39', '2026-12-01', '2026-12-31');
    $version = Fx::version($this->tenant);

    try {
        Fx::negotiated($this->tenant, $this->company, $this->sku, '4,19', $from, $until, $this->manager);
        $this->fail('A sobreposição deveria ter sido recusada.');
    } catch (NegotiatedPriceOverlap $e) {
        expect($e)->toBeInstanceOf(ConflictingState::class)
            ->and($e->errorCode())->toBe('negotiated_price_overlap')
            ->and($e->getMessage())->toContain('de 01/12/2026 a 31/12/2026')
            ->and($e->getMessage())->toContain('R$ 4,39')
            ->and($e->details())->toHaveKey('conflicting_id');
    }

    expect(($this->stored)())->toBe([[439, '2026-12-01', '2026-12-31', 'active']])
        ->and(Fx::version($this->tenant))->toBe($version);
})->with([
    'mesmo período' => ['2026-12-01', '2026-12-31'],
    'começa no último dia' => ['2026-12-31', '2027-01-31'],
    'termina no primeiro dia' => ['2026-11-01', '2026-12-01'],
    'contida no período' => ['2026-12-10', '2026-12-20'],
    'sem término, antes do período' => ['2026-11-20', null],
]);

it('descreve condição existente sem término na recusa', function (): void {
    Fx::negotiated($this->tenant, $this->company, $this->sku, '4,39', '2026-12-01');

    expect(fn () => Fx::negotiated($this->tenant, $this->company, $this->sku, '4,19', '2027-03-01', '2027-03-31'))
        ->toThrow(NegotiatedPriceOverlap::class, 'a partir de 01/12/2026, sem término');
});

it('aceita períodos adjacentes, outra empresa ou outro SKU no mesmo período', function (): void {
    $otherCompany = Fx::company($this->tenant, 'Mercearia Recanto');
    $otherSku = Fx::sku($this->tenant, 'LEI-DESN', 'Leite UHT desnatado 1 L');

    Fx::negotiated($this->tenant, $this->company, $this->sku, '4,39', '2026-12-01', '2026-12-31');
    Fx::negotiated($this->tenant, $this->company, $this->sku, '4,49', '2027-01-01');
    Fx::negotiated($this->tenant, $this->company, $this->sku, '4,29', '2026-11-01', '2026-11-30');
    Fx::negotiated($this->tenant, $otherCompany, $this->sku, '4,39', '2026-12-01', '2026-12-31');
    Fx::negotiated($this->tenant, $this->company, $otherSku, '4,39', '2026-12-01', '2026-12-31');

    expect($this->tenant->system(static fn (): int => NegotiatedPrice::query()->count()))->toBe(5);
});

it('libera a vigência após a revogação', function (): void {
    $first = Fx::negotiated($this->tenant, $this->company, $this->sku, '4,39', '2026-12-01', '2026-12-31');
    $this->tenant->actingAs($this->manager, static fn () => app(RevokeNegotiatedPrice::class)->handle($first, 'Reajuste do fornecedor'));

    Fx::negotiated($this->tenant, $this->company, $this->sku, '4,59', '2026-12-01', '2026-12-31');

    expect(($this->stored)())->toEqualCanonicalizing([
        [439, '2026-12-01', '2026-12-31', 'revoked'],
        [459, '2026-12-01', '2026-12-31', 'active'],
    ]);
});

it('valida preço, vigência e SKU arquivado', function (): void {
    $archived = Fx::sku($this->tenant, 'LEI-ANTIGO', 'Leite pasteurizado 1 L', status: SkuStatus::Archived);

    expect(fn () => Fx::negotiated($this->tenant, $this->company, $this->sku, '0,00', '2026-12-01'))
        ->toThrow(InvalidUnitPrice::class, 'O preço negociado deve ser maior que zero.')
        ->and(fn () => Fx::negotiated($this->tenant, $this->company, $this->sku, '4,00', '2026-12-10', '2026-12-09'))
        ->toThrow(InvalidValidityPeriod::class)
        ->and(fn () => Fx::negotiated($this->tenant, $this->company, $archived, '4,00', '2026-12-01'))
        ->toThrow(SkuNotPriceable::class);

    expect(($this->stored)())->toBe([]);
});

it('revoga com justificativa registrada na auditoria e incrementa a versão de preços', function (): void {
    $price = Fx::negotiated($this->tenant, $this->company, $this->sku, '4,39', '2026-12-01');
    $version = Fx::version($this->tenant);

    $revoked = $this->tenant->actingAs($this->manager, static fn () => app(RevokeNegotiatedPrice::class)->handle($price, '  Cliente migrou para a tabela do grupo  '));
    $log = $this->tenant->system(static fn (): AuditLog => AuditLog::query()->where('action', 'negotiated_price.revoked')->sole());

    expect($revoked->status)->toBe(NegotiatedPriceStatus::Revoked)
        ->and($log->reason)->toBe('Cliente migrou para a tabela do grupo')
        ->and($log->data['unit_price_cents'])->toBe(439)
        ->and(Fx::version($this->tenant))->toBe($version + 1);
});

it('exige justificativa e recusa revogar duas vezes', function (): void {
    $price = Fx::negotiated($this->tenant, $this->company, $this->sku, '4,39', '2026-12-01');
    $revoke = fn (string $reason) => $this->tenant->actingAs($this->manager, static fn () => app(RevokeNegotiatedPrice::class)->handle($price, $reason));

    expect(fn () => $revoke('   '))->toThrow(JustificationRequired::class)
        ->and(fn () => $revoke('ok'))->toThrow(JustificationRequired::class);

    $revoke('Condição substituída');

    expect(fn () => $revoke('Nova tentativa'))->toThrow(NegotiatedPriceNotActive::class, 'Esta condição negociada já foi revogada.');
});

it('exige pricing.manage para registrar e revogar', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);
    $price = Fx::negotiated($this->tenant, $this->company, $this->sku, '4,39', '2026-12-01');

    expect(fn () => Fx::negotiated($this->tenant, $this->company, $this->sku, '4,00', '2027-01-01', actor: $actor))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->tenant->actingAs($actor, static fn () => app(RevokeNegotiatedPrice::class)->handle($price, 'Sem permissão para isto')))
        ->toThrow(AuthorizationException::class);

    expect(($this->stored)())->toBe([[439, '2026-12-01', null, 'active']]);
})->with([
    'representante comercial' => [SystemRole::SalesRep],
    'financeiro' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);

it('nega o registro de condições no contexto de comprador', function (): void {
    $buyer = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);

    expect(fn () => Fx::negotiated($this->tenant, $this->company, $this->sku, '1,00', '2026-12-01', actor: $buyer))
        ->toThrow(AuthorizationException::class);
});

it('trata empresa, SKU e condição de outro tenant como inexistentes', function (): void {
    $other = TenantScenario::create();
    $foreignCompany = Fx::company($other, 'Supermercado Vila Nova');
    $foreignSku = Fx::sku($other, 'LEI-1L', 'Leite UHT integral 1 L');
    $foreignPrice = Fx::negotiated($other, $foreignCompany, $foreignSku, '3,99', '2026-12-01');

    $save = fn ($company, $sku) => $this->tenant->actingAs($this->manager, static fn () => app(SaveNegotiatedPrice::class)->handle(
        $company,
        $sku,
        Money::parse('1,00'),
        CarbonImmutable::parse('2026-12-01'),
    ));

    expect(fn () => $save($foreignCompany, $this->sku))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $save($this->company, $foreignSku))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->tenant->actingAs($this->manager, static fn () => app(RevokeNegotiatedPrice::class)->handle($foreignPrice, 'Tentativa indevida')))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->stored)())->toBe([])
        ->and($other->system(static fn () => NegotiatedPrice::query()->findOrFail($foreignPrice->id)->status))->toBe(NegotiatedPriceStatus::Active);
});
