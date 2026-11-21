<?php

declare(strict_types=1);

use App\Actions\Buyers\SaveBuyerCompany;
use App\Data\Buyers\BuyerCompanyData;
use App\Domain\Buyers\Exceptions\BuyerCompanyAlreadyRegistered;
use App\Domain\Buyers\Exceptions\InvalidRecordField;
use App\Domain\Buyers\Exceptions\InvalidSalesRepresentative;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\ConflictingState;
use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);

    $this->save = fn (Membership $actor, ?BuyerCompany $company, BuyerCompanyData $data, ?TenantScenario $tenant = null): BuyerCompany => ($tenant ?? $this->tenant)->actingAs(
        $actor,
        static fn (): BuyerCompany => app(SaveBuyerCompany::class)->handle($company, $data),
    );

    $this->companies = fn (?TenantScenario $tenant = null): int => ($tenant ?? $this->tenant)->system(static fn (): int => BuyerCompany::query()->count());
});

it('cadastra a empresa com dados normalizados, grupo e representante, e registra a auditoria', function (): void {
    $group = Fixtures::group($this->tenant, 'CONSTRUTORAS', 'Construtoras');
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    $document = BrazilianDocuments::cnpj();
    $versionBefore = Fixtures::priceVersion($this->tenant);

    $company = ($this->save)($this->manager, null, Fixtures::companyData([
        'legalName' => '  Construtora Horizonte Verde Ltda ',
        'tradeName' => ' Horizonte Verde ',
        'document' => Cnpj::fromString(Cnpj::format($document)),
        'email' => ' Compras@HorizonteVerde.example ',
        'stateRegistration' => '   ',
        'notes' => 'Entregas somente pela manhã.',
        'customerGroupId' => $group->id,
        'salesRepMembershipId' => $seller->id,
    ]));

    $stored = Fixtures::company($this->tenant, $company->id);

    expect($stored->legal_name)->toBe('Construtora Horizonte Verde Ltda')
        ->and($stored->trade_name)->toBe('Horizonte Verde')
        ->and($stored->document)->toBe($document)
        ->and($stored->email)->toBe('compras@horizonteverde.example')
        ->and($stored->state_registration)->toBeNull()
        ->and($stored->notes)->toBe('Entregas somente pela manhã.')
        ->and($stored->customer_group_id)->toBe($group->id)
        ->and($stored->sales_rep_membership_id)->toBe($seller->id)
        ->and($stored->status)->toBe(BuyerCompanyStatus::Active)
        ->and($stored->tenant_id)->toBe($this->tenant->organization->id);

    $logs = Fixtures::audits($this->tenant, 'buyer_company.created');

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->subject_type)->toBe('buyer_company')
        ->and($logs[0]->subject_id)->toBe($company->id)
        ->and($logs[0]->actor_membership_id)->toBe($this->manager->id)
        ->and($logs[0]->data['after']['document'])->toBe($document)
        ->and($logs[0]->data['after']['customer_group_id'])->toBe($group->id)
        ->and($logs[0]->data['after']['sales_rep_membership_id'])->toBe($seller->id)
        // Empresa nova ainda não tem preços em cache: a versão não muda.
        ->and(Fixtures::priceVersion($this->tenant))->toBe($versionBefore);
});

it('exige buyers.manage para cadastrar ou alterar', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);
    $existing = $this->tenant->addBuyerCompany(['trade_name' => 'Ferragens Bandeirantes']);

    expect(fn () => ($this->save)($actor, null, Fixtures::companyData()))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($actor, $existing, Fixtures::companyData(['tradeName' => 'Alterada sem permissão'])))
        ->toThrow(AuthorizationException::class);

    expect(($this->companies)())->toBe(1)
        ->and(Fixtures::company($this->tenant, $existing->id)->trade_name)->toBe('Ferragens Bandeirantes')
        ->and(Fixtures::auditCount($this->tenant))->toBe(0);
})->with([
    'representante comercial' => [SystemRole::SalesRep],
    'financeiro' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
    'estoque e expedição' => [SystemRole::Warehouse],
]);

it('recusa o cadastro a partir do contexto de empresa compradora', function (): void {
    $buyerAdmin = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), SystemRole::BuyerAdmin);

    expect(fn () => ($this->save)($buyerAdmin, null, Fixtures::companyData()))->toThrow(AuthorizationException::class)
        ->and(($this->companies)())->toBe(1);
});

it('recusa CNPJ já cadastrado no mesmo distribuidor com conflito de código estável', function (): void {
    $document = BrazilianDocuments::cnpj();
    $this->tenant->addBuyerCompany(['document' => $document]);

    try {
        ($this->save)($this->manager, null, Fixtures::companyData(['document' => Cnpj::fromString($document)]));
        $this->fail('O CNPJ duplicado deveria ter sido recusado.');
    } catch (BuyerCompanyAlreadyRegistered $e) {
        expect($e)->toBeInstanceOf(ConflictingState::class)
            ->and($e->errorCode())->toBe('buyer_company_already_registered')
            ->and($e->details())->toBe(['field' => 'document']);
    }

    expect(($this->companies)())->toBe(1)
        ->and(Fixtures::audits($this->tenant, 'buyer_company.created'))->toBeEmpty();
});

it('aceita o mesmo CNPJ em outro distribuidor, sem compartilhar o cadastro', function (): void {
    $document = BrazilianDocuments::cnpj();
    $other = TenantScenario::create();
    $other->addBuyerCompany(['document' => $document, 'trade_name' => 'Cadastro do outro distribuidor']);

    $company = ($this->save)($this->manager, null, Fixtures::companyData(['document' => Cnpj::fromString($document)]));

    expect(Fixtures::company($this->tenant, $company->id)->trade_name)->toBe('Horizonte Verde')
        ->and(($this->companies)($other))->toBe(1)
        ->and($other->system(static fn (): string => BuyerCompany::query()->sole()->trade_name))->toBe('Cadastro do outro distribuidor');
});

it('recusa, na edição, o CNPJ de outra empresa do distribuidor', function (): void {
    $documentA = BrazilianDocuments::cnpj();
    $this->tenant->addBuyerCompany(['document' => $documentA]);
    $companyB = $this->tenant->addBuyerCompany(['trade_name' => 'Elétrica Paulista']);

    expect(fn () => ($this->save)($this->manager, $companyB, Fixtures::companyData([
        'tradeName' => 'Elétrica Paulista',
        'document' => Cnpj::fromString($documentA),
    ])))->toThrow(BuyerCompanyAlreadyRegistered::class);

    expect(Fixtures::company($this->tenant, $companyB->id)->document)->toBe($companyB->document)
        ->and(Fixtures::audits($this->tenant, 'buyer_company.updated'))->toBeEmpty();
});

it('recusa grupo de clientes de outro distribuidor', function (): void {
    $foreignGroup = Fixtures::group(TenantScenario::create(), 'REVENDAS', 'Revendas');

    expect(fn () => ($this->save)($this->manager, null, Fixtures::companyData(['customerGroupId' => $foreignGroup->id])))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->companies)())->toBe(0);
});

it('trata identificador de grupo malformado como inexistente', function (): void {
    expect(fn () => ($this->save)($this->manager, null, Fixtures::companyData(['customerGroupId' => "1' or '1'='1"])))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->companies)())->toBe(0);
});

it('exige representante que seja associação de equipe ativa', function (Closure $representative): void {
    $membership = $representative($this->tenant);
    $before = ($this->companies)();

    try {
        ($this->save)($this->manager, null, Fixtures::companyData(['salesRepMembershipId' => $membership->id]));
        $this->fail('O representante deveria ter sido recusado.');
    } catch (InvalidSalesRepresentative $e) {
        expect($e->errorCode())->toBe('invalid_sales_representative')
            ->and($e->details())->toBe(['field' => 'sales_rep_membership_id']);
    }

    expect(($this->companies)())->toBe($before)
        ->and(Fixtures::audits($this->tenant, 'buyer_company.created'))->toBeEmpty();
})->with([
    'usuário de empresa compradora' => [fn (TenantScenario $tenant): Membership => $tenant->addBuyerUser($tenant->addBuyerCompany())],
    'equipe com acesso revogado' => [fn (TenantScenario $tenant): Membership => tap($tenant->addStaff(SystemRole::SalesRep), static function (Membership $m) use ($tenant): void {
        $tenant->system(static fn () => Membership::query()->findOrFail($m->id)->forceFill([
            'status' => MembershipStatus::Revoked,
            'revoked_at' => now(),
            'revoke_reason' => 'Desligamento do representante',
        ])->save());
    })],
]);

it('não aceita representante de outro distribuidor', function (): void {
    $foreignSeller = TenantScenario::create()->addStaff(SystemRole::SalesRep);

    expect(fn () => ($this->save)($this->manager, null, Fixtures::companyData(['salesRepMembershipId' => $foreignSeller->id])))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->companies)())->toBe(0);
});

it('mantém o representante atual revogado ao editar outros campos', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    $company = $this->tenant->addBuyerCompany(['sales_rep_membership_id' => $seller->id, 'trade_name' => 'Madeireira Ipê']);
    $this->tenant->system(static fn () => Membership::query()->findOrFail($seller->id)->forceFill([
        'status' => MembershipStatus::Revoked,
        'revoked_at' => now(),
        'revoke_reason' => 'Encerramento do contrato de representação',
    ])->save());

    $updated = ($this->save)($this->manager, $company, Fixtures::companyData([
        'tradeName' => 'Madeireira Ipê Roxo',
        'document' => Cnpj::fromString($company->document),
        'salesRepMembershipId' => $seller->id,
    ]));

    expect($updated->trade_name)->toBe('Madeireira Ipê Roxo')
        ->and(Fixtures::company($this->tenant, $company->id)->sales_rep_membership_id)->toBe($seller->id);
});

it('incrementa a versão de preços na mesma transação quando o grupo muda', function (): void {
    $construtoras = Fixtures::group($this->tenant, 'CONSTRUTORAS', 'Construtoras');
    $revendas = Fixtures::group($this->tenant, 'REVENDAS', 'Revendas');
    $company = $this->tenant->addBuyerCompany(['customer_group_id' => $construtoras->id]);
    $data = fn (?string $groupId, string $tradeName = 'Horizonte Verde'): BuyerCompanyData => Fixtures::companyData([
        'tradeName' => $tradeName,
        'document' => Cnpj::fromString($company->document),
        'customerGroupId' => $groupId,
    ]);

    ($this->save)($this->manager, $company, $data($construtoras->id));
    $version = Fixtures::priceVersion($this->tenant);

    ($this->save)($this->manager, $company, $data($revendas->id));
    expect(Fixtures::priceVersion($this->tenant))->toBe($version + 1);

    ($this->save)($this->manager, $company, $data($revendas->id, 'Horizonte Verde Materiais'));
    expect(Fixtures::priceVersion($this->tenant))->toBe($version + 1);

    ($this->save)($this->manager, $company, $data(null, 'Horizonte Verde Materiais'));
    expect(Fixtures::priceVersion($this->tenant))->toBe($version + 2)
        ->and(Fixtures::company($this->tenant, $company->id)->customer_group_id)->toBeNull();

    $updates = Fixtures::audits($this->tenant, 'buyer_company.updated');

    expect($updates->map(static fn ($log): bool => $log->data['price_version_bumped'])->all())->toBe([false, true, false, true])
        ->and($updates[1]->data['before'])->toBe(['customer_group_id' => $construtoras->id])
        ->and($updates[1]->data['after'])->toBe(['customer_group_id' => $revendas->id]);
});

it('desfaz a alteração e a versão de preços quando a transação falha', function (): void {
    $group = Fixtures::group($this->tenant, 'CONSTRUTORAS', 'Construtoras');
    $company = $this->tenant->addBuyerCompany();
    $version = Fixtures::priceVersion($this->tenant);

    expect(fn () => $this->tenant->actingAs($this->manager, function () use ($company, $group): void {
        app(SaveBuyerCompany::class)->handle($company, Fixtures::companyData([
            'document' => Cnpj::fromString($company->document),
            'customerGroupId' => $group->id,
        ]));

        throw new RuntimeException('Falha posterior na mesma requisição.');
    }))->toThrow(RuntimeException::class);

    expect(Fixtures::priceVersion($this->tenant))->toBe($version)
        ->and(Fixtures::company($this->tenant, $company->id)->customer_group_id)->toBeNull()
        ->and(Fixtures::audits($this->tenant, 'buyer_company.updated'))->toBeEmpty();
});

it('não registra auditoria quando nada muda', function (): void {
    $company = ($this->save)($this->manager, null, Fixtures::companyData());

    ($this->save)($this->manager, $company, Fixtures::companyData(['document' => Cnpj::fromString($company->document)]));

    expect(Fixtures::audits($this->tenant, 'buyer_company.updated'))->toBeEmpty();
});

it('não altera empresa de outro distribuidor', function (): void {
    $other = TenantScenario::create();
    $foreign = $other->addBuyerCompany(['trade_name' => 'Distribuição Cerrado']);

    expect(fn () => ($this->save)($this->manager, $foreign, Fixtures::companyData([
        'tradeName' => 'Alterada indevidamente',
        'document' => Cnpj::fromString($foreign->document),
    ])))->toThrow(ModelNotFoundException::class);

    expect($other->system(static fn (): string => BuyerCompany::query()->findOrFail($foreign->id)->trade_name))->toBe('Distribuição Cerrado');
});

it('recusa campos obrigatórios vazios e textos acima do limite', function (array $overrides, string $field): void {
    try {
        ($this->save)($this->manager, null, Fixtures::companyData($overrides));
        $this->fail('O cadastro deveria ter sido recusado.');
    } catch (InvalidRecordField $e) {
        expect($e->details())->toBe(['field' => $field]);
    }

    expect(($this->companies)())->toBe(0);
})->with([
    'razão social vazia' => [['legalName' => '   '], 'legal_name'],
    'nome fantasia vazio' => [['tradeName' => ''], 'trade_name'],
    'e-mail inválido' => [['email' => 'compras@'], 'email'],
    'telefone longo' => [['phone' => str_repeat('9', 21)], 'phone'],
]);
