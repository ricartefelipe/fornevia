<?php

declare(strict_types=1);

use App\Actions\Buyers\SetBuyerPaymentTerms;
use App\Domain\Buyers\Exceptions\InvalidPaymentTerms;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);

    $this->cash = Fixtures::paymentTerm($this->tenant, 'AVISTA');
    $this->days28 = Fixtures::paymentTerm($this->tenant, '28D');
    $this->days3060 = Fixtures::paymentTerm($this->tenant, '30-60');

    $this->set = fn (Membership $actor, BuyerCompany $company, array $ids, ?string $default): mixed => $this->tenant->actingAs(
        $actor,
        static fn (): mixed => app(SetBuyerPaymentTerms::class)->handle($company, $ids, $default),
    );

    $this->assigned = fn (?BuyerCompany $company = null): array => $this->tenant->system(fn (): array => BuyerPaymentTerm::query()
        ->with('paymentTerm')
        ->where('buyer_company_id', ($company ?? $this->company)->id)
        ->get()
        ->sortBy(static fn (BuyerPaymentTerm $row): string => $row->paymentTerm->code)
        ->mapWithKeys(static fn (BuyerPaymentTerm $row): array => [$row->paymentTerm->code => $row->is_default])
        ->all());
});

it('define as condições permitidas e a padrão, com auditoria', function (): void {
    ($this->set)($this->manager, $this->company, [$this->cash->id, $this->days28->id], $this->days28->id);

    expect(($this->assigned)())->toBe(['28D' => true, 'AVISTA' => false]);

    $log = Fixtures::audits($this->tenant, 'buyer_company.payment_terms_changed')->sole();

    expect($log->subject_type)->toBe('buyer_company')
        ->and($log->subject_id)->toBe($this->company->id)
        ->and($log->data)->toEqual([
            'buyer_company_id' => $this->company->id,
            'before' => ['terms' => [], 'default' => null],
            'after' => ['terms' => ['28D', 'AVISTA'], 'default' => '28D'],
        ]);
});

it('substitui a lista, troca a padrão e remove as condições excluídas', function (): void {
    ($this->set)($this->manager, $this->company, [$this->cash->id, $this->days28->id], $this->cash->id);
    ($this->set)($this->manager, $this->company, [$this->days28->id, $this->days3060->id, $this->days28->id], $this->days3060->id);

    expect(($this->assigned)())->toBe(['28D' => false, '30-60' => true])
        ->and(Fixtures::audits($this->tenant, 'buyer_company.payment_terms_changed')->last()->data['before'])
        ->toEqual(['terms' => ['28D', 'AVISTA'], 'default' => 'AVISTA']);
});

it('remove todas as condições com lista vazia', function (): void {
    ($this->set)($this->manager, $this->company, [$this->cash->id], $this->cash->id);
    ($this->set)($this->manager, $this->company, [], null);

    expect(($this->assigned)())->toBe([]);
});

it('não registra auditoria quando a lista não muda', function (): void {
    ($this->set)($this->manager, $this->company, [$this->cash->id], $this->cash->id);
    ($this->set)($this->manager, $this->company, [$this->cash->id], $this->cash->id);

    expect(Fixtures::audits($this->tenant, 'buyer_company.payment_terms_changed'))->toHaveCount(1);
});

it('exige condição padrão pertencente à lista', function (array $ids, ?string $default, string $message): void {
    ($this->set)($this->manager, $this->company, [$this->cash->id], $this->cash->id);

    try {
        ($this->set)($this->manager, $this->company, array_map(fn (string $code): string => $this->{$code}->id, $ids), $default === null ? null : $this->{$default}->id);
        $this->fail('A condição padrão deveria ser validada.');
    } catch (InvalidPaymentTerms $e) {
        expect($e->errorCode())->toBe('invalid_payment_terms')
            ->and($e->getMessage())->toBe($message)
            ->and($e->details())->toBe(['field' => 'default']);
    }

    expect(($this->assigned)())->toBe(['AVISTA' => true]);
})->with([
    'padrão fora da lista' => [['days28'], 'cash', 'A condição padrão precisa estar entre as condições permitidas.'],
    'lista sem padrão' => [['days28', 'days3060'], null, 'Escolha a condição de pagamento padrão.'],
    'padrão com lista vazia' => [[], 'cash', 'A condição padrão precisa estar entre as condições permitidas.'],
]);

it('recusa condição inativa', function (): void {
    $this->tenant->system(fn () => PaymentTerm::query()->findOrFail($this->days3060->id)->forceFill(['is_active' => false])->save());

    expect(fn () => ($this->set)($this->manager, $this->company, [$this->cash->id, $this->days3060->id], $this->cash->id))
        ->toThrow(InvalidPaymentTerms::class, 'Condições inativas não podem ser permitidas: 30/60 dias.');

    expect(($this->assigned)())->toBe([]);
});

it('trata condição de outro distribuidor ou identificador malformado como inexistente', function (Closure $termId): void {
    $id = $termId();

    expect(fn () => ($this->set)($this->manager, $this->company, [$this->cash->id, $id], $this->cash->id))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->assigned)())->toBe([]);
})->with([
    'outro distribuidor' => [fn (): string => Fixtures::paymentTerm(TenantScenario::create(), 'AVISTA')->id],
    'malformado' => [fn (): string => 'AVISTA'],
]);

it('exige buyers.manage para alterar condições', function (): void {
    $finance = $this->tenant->addStaff(SystemRole::Finance);

    expect(fn () => ($this->set)($finance, $this->company, [$this->cash->id], $this->cash->id))
        ->toThrow(AuthorizationException::class);

    expect(($this->assigned)())->toBe([]);
});

it('não altera condições de empresa de outro distribuidor', function (): void {
    $other = TenantScenario::create();
    $foreign = $other->addBuyerCompany();

    expect(fn () => ($this->set)($this->manager, $foreign, [$this->cash->id], $this->cash->id))
        ->toThrow(ModelNotFoundException::class);

    expect($other->system(static fn (): int => BuyerPaymentTerm::query()->count()))->toBe(0);
});

it('trata identificadores em maiúsculas como os mesmos registros', function (): void {
    ($this->set)($this->manager, $this->company, [$this->cash->id], $this->cash->id);
    ($this->set)($this->manager, $this->company, [strtoupper($this->cash->id), $this->days28->id], strtoupper($this->days28->id));

    expect(($this->assigned)())->toBe(['28D' => true, 'AVISTA' => false]);
});
