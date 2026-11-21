<?php

declare(strict_types=1);

use App\Actions\Buyers\DeleteBuyerContact;
use App\Actions\Buyers\SaveBuyerContact;
use App\Data\Buyers\BuyerContactData;
use App\Domain\Buyers\Exceptions\InvalidRecordField;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerContact;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);

    $this->save = fn (Membership $actor, BuyerCompany $company, ?BuyerContact $contact, BuyerContactData $data): BuyerContact => $this->tenant->actingAs(
        $actor,
        static fn (): BuyerContact => app(SaveBuyerContact::class)->handle($company, $contact, $data),
    );

    $this->delete = fn (Membership $actor, BuyerCompany $company, BuyerContact $contact): null => $this->tenant->actingAs(
        $actor,
        static function () use ($company, $contact): null {
            app(DeleteBuyerContact::class)->handle($company, $contact);

            return null;
        },
    );

    $this->contacts = fn (?BuyerCompany $company = null): array => $this->tenant->system(fn (): array => BuyerContact::query()
        ->where('buyer_company_id', ($company ?? $this->company)->id)
        ->orderBy('name')
        ->get()
        ->mapWithKeys(static fn (BuyerContact $c): array => [$c->name => [$c->email, $c->is_primary]])
        ->all());
});

it('cadastra contato com e-mail normalizado e auditoria vinculada à empresa', function (): void {
    $contact = ($this->save)($this->manager, $this->company, null, new BuyerContactData(
        name: ' Patrícia Nogueira ',
        email: ' Patricia.Nogueira@SerraAzul.example ',
        phone: '(11) 98888-1234',
        position: 'Compradora',
    ));

    expect(($this->contacts)())->toBe(['Patrícia Nogueira' => ['patricia.nogueira@serraazul.example', false]]);

    $log = Fixtures::audits($this->tenant, 'buyer_contact.created')->sole();

    expect($log->subject_id)->toBe($contact->id)
        ->and($log->subject_type)->toBe('buyer_contact')
        ->and($log->data['buyer_company_id'])->toBe($this->company->id);
});

it('mantém um único contato principal por empresa', function (): void {
    $first = ($this->save)($this->manager, $this->company, null, new BuyerContactData('Patrícia Nogueira', isPrimary: true));
    $otherCompany = $this->tenant->addBuyerCompany();
    ($this->save)($this->manager, $otherCompany, null, new BuyerContactData('Contato de outra empresa', isPrimary: true));

    ($this->save)($this->manager, $this->company, null, new BuyerContactData('Rodrigo Albuquerque', isPrimary: true));

    expect(($this->contacts)())->toBe([
        'Patrícia Nogueira' => [null, false],
        'Rodrigo Albuquerque' => [null, true],
    ])->and(($this->contacts)($otherCompany))->toBe(['Contato de outra empresa' => [null, true]])
        ->and(Fixtures::audits($this->tenant, 'buyer_contact.created')->last()->data['replaced_primary_ids'])->toBe([$first->id]);

    ($this->save)($this->manager, $this->company, $first, new BuyerContactData('Patrícia Nogueira', isPrimary: true));

    expect(($this->contacts)())->toBe([
        'Patrícia Nogueira' => [null, true],
        'Rodrigo Albuquerque' => [null, false],
    ]);
});

it('recusa e-mail inválido e nome vazio', function (BuyerContactData $data, string $field): void {
    try {
        ($this->save)($this->manager, $this->company, null, $data);
        $this->fail('O contato deveria ser recusado.');
    } catch (InvalidRecordField $e) {
        expect($e->details())->toBe(['field' => $field]);
    }

    expect(($this->contacts)())->toBe([]);
})->with([
    'e-mail inválido' => [new BuyerContactData('Patrícia Nogueira', email: 'patricia@'), 'email'],
    'nome vazio' => [new BuyerContactData('   '), 'name'],
]);

it('remove contato registrando os dados removidos', function (): void {
    $contact = ($this->save)($this->manager, $this->company, null, new BuyerContactData('Rodrigo Albuquerque', email: 'rodrigo@serraazul.example'));

    ($this->delete)($this->manager, $this->company, $contact);

    expect(($this->contacts)())->toBe([])
        ->and(Fixtures::audits($this->tenant, 'buyer_contact.deleted')->sole()->data)->toEqual([
            'buyer_company_id' => $this->company->id,
            'before' => [
                'name' => 'Rodrigo Albuquerque',
                'email' => 'rodrigo@serraazul.example',
                'phone' => null,
                'position' => null,
                'is_primary' => false,
            ],
        ]);
});

it('não altera nem remove contato por meio de outra empresa ou de outro distribuidor', function (): void {
    $otherCompany = $this->tenant->addBuyerCompany();
    $contact = ($this->save)($this->manager, $otherCompany, null, new BuyerContactData('Contato original'));
    $other = TenantScenario::create();

    expect(fn () => ($this->save)($this->manager, $this->company, $contact, new BuyerContactData('Alterado')))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->delete)($this->manager, $this->company, $contact))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->save)($this->manager, $other->addBuyerCompany(), null, new BuyerContactData('Externo')))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->contacts)($otherCompany))->toBe(['Contato original' => [null, false]]);
});

it('exige buyers.manage para cadastrar e remover contatos', function (): void {
    $contact = ($this->save)($this->manager, $this->company, null, new BuyerContactData('Patrícia Nogueira'));
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    expect(fn () => ($this->save)($seller, $this->company, null, new BuyerContactData('Sem permissão')))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->delete)($seller, $this->company, $contact))
        ->toThrow(AuthorizationException::class);

    expect(($this->contacts)())->toBe(['Patrícia Nogueira' => [null, false]]);
});
