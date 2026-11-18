<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Data\Buyers\BuyerCompanyData;
use App\Domain\Buyers\Exceptions\BuyerCompanyAlreadyRegistered;
use App\Domain\Buyers\Exceptions\InvalidSalesRepresentative;
use App\Domain\Buyers\RecordText;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Models\Identity\Membership;
use App\Services\Audit\AuditRecorder;
use App\Services\Pricing\PriceVersion;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra ou altera uma empresa compradora.
 *
 * - CNPJ único por distribuidor (o mesmo CNPJ pode existir em outro tenant);
 * - grupo de clientes do próprio tenant;
 * - representante responsável: associação de equipe ativa do tenant
 *   (validada quando escolhida ou trocada);
 * - troca de grupo altera o preço resolvido: a versão de preços do tenant
 *   é incrementada na mesma transação.
 */
final readonly class SaveBuyerCompany
{
    /** @var list<string> */
    public const array AUDITED = [
        'legal_name', 'trade_name', 'document', 'state_registration', 'email', 'phone',
        'notes', 'customer_group_id', 'sales_rep_membership_id',
    ];

    private const string DUPLICATE_MESSAGE = 'Já existe uma empresa compradora cadastrada com este CNPJ.';

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private PriceVersion $prices,
        private BuyerRecords $records,
    ) {}

    public function handle(?BuyerCompany $company, BuyerCompanyData $data): BuyerCompany
    {
        $this->access->authorize(Permission::BuyersManage);

        $attributes = [
            'legal_name' => RecordText::required($data->legalName, 'legal_name', 'a razão social', 200),
            'trade_name' => RecordText::required($data->tradeName, 'trade_name', 'o nome fantasia', 160),
            'document' => $data->document->digits,
            'state_registration' => RecordText::optional($data->stateRegistration, 'state_registration', 'a inscrição estadual', 20),
            'email' => RecordText::email($data->email, 'email'),
            'phone' => RecordText::optional($data->phone, 'phone', 'o telefone', 20),
            'notes' => RecordText::optional($data->notes, 'notes', 'as observações', 2000),
        ];

        try {
            return DB::transaction(fn (): BuyerCompany => $this->save($company, $attributes, $data));
        } catch (UniqueConstraintViolationException) {
            // Cadastro concorrente com o mesmo CNPJ (unique tenant_id, document).
            throw new BuyerCompanyAlreadyRegistered(self::DUPLICATE_MESSAGE, ['field' => 'document']);
        }
    }

    /**
     * @param  array<string, string|null>  $attributes
     */
    private function save(?BuyerCompany $company, array $attributes, BuyerCompanyData $data): BuyerCompany
    {
        $company = $company !== null ? $this->records->lockCompany($company) : new BuyerCompany;
        $creating = ! $company->exists;

        $duplicate = BuyerCompany::query()
            ->where('document', $attributes['document'])
            ->when(! $creating, static fn ($query) => $query->whereKeyNot($company->id))
            ->exists();

        if ($duplicate) {
            throw new BuyerCompanyAlreadyRegistered(self::DUPLICATE_MESSAGE, ['field' => 'document']);
        }

        $groupId = $data->customerGroupId !== null && $data->customerGroupId !== ''
            ? CustomerGroup::query()->findOrFail(UntrustedId::uuid($data->customerGroupId, CustomerGroup::class))->id
            : null;

        $salesRepId = $this->salesRep($data->salesRepMembershipId, $creating ? null : $company->sales_rep_membership_id);

        $before = $creating ? [] : $company->only(self::AUDITED);

        $company->fill([
            'legal_name' => $attributes['legal_name'],
            'trade_name' => $attributes['trade_name'],
            'document' => $attributes['document'],
            'state_registration' => $attributes['state_registration'],
            'email' => $attributes['email'],
            'phone' => $attributes['phone'],
            'notes' => $attributes['notes'],
        ]);
        $company->forceFill([
            'customer_group_id' => $groupId,
            'sales_rep_membership_id' => $salesRepId,
        ]);

        if (! $creating && ! $company->isDirty()) {
            return $company;
        }

        $groupChanged = ! $creating && $company->isDirty('customer_group_id');
        $changed = array_keys(array_intersect_key($company->getDirty(), array_flip(self::AUDITED)));
        $company->save();

        if ($groupChanged) {
            $this->prices->bump();
        }

        $after = $company->only(self::AUDITED);

        $this->audit->record($creating ? 'buyer_company.created' : 'buyer_company.updated', $company, $creating
            ? ['after' => $after]
            : [
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
                'price_version_bumped' => $groupChanged,
            ]);

        return $company;
    }

    /**
     * O representante escolhido (ou trocado) precisa ser associação de equipe
     * ativa do tenant. Manter o representante atual não exige revalidação,
     * para não impedir a edição de outros campos após uma revogação.
     */
    private function salesRep(?string $requested, ?string $current): ?string
    {
        if ($requested === null || $requested === '') {
            return null;
        }

        $id = UntrustedId::uuid($requested, Membership::class);

        if ($id === $current) {
            return $current;
        }

        $membership = Membership::query()->findOrFail($id);

        if ($membership->kind !== MembershipKind::Staff || ! $membership->isActive()) {
            throw new InvalidSalesRepresentative(
                'O representante responsável precisa ser um usuário ativo da equipe do distribuidor.',
                ['field' => 'sales_rep_membership_id'],
            );
        }

        return $membership->id;
    }
}
