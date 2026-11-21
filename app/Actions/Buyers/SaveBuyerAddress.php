<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Data\Buyers\BuyerAddressData;
use App\Domain\Buyers\BrazilianState;
use App\Domain\Buyers\PostalCode;
use App\Domain\Buyers\RecordText;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra ou altera um endereço de cobrança ou entrega.
 *
 * Há no máximo um endereço padrão ativo por tipo e empresa (índice único
 * parcial no banco). Ao marcar um endereço como padrão, o anterior do mesmo
 * tipo é desmarcado na mesma transação, sob bloqueio da empresa, o que
 * serializa marcações concorrentes. Endereço inativo não pode ser padrão.
 */
final readonly class SaveBuyerAddress
{
    /** @var list<string> */
    private const array AUDITED = [
        'kind', 'label', 'recipient', 'street', 'number', 'complement', 'district', 'city', 'state',
        'postal_code', 'buyer_unit_id', 'is_default', 'is_active',
    ];

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private BuyerRecords $records,
    ) {}

    public function handle(BuyerCompany $company, ?BuyerAddress $address, BuyerAddressData $data): BuyerAddress
    {
        $this->access->authorize(Permission::BuyersManage);

        $attributes = [
            'kind' => $data->kind,
            'label' => RecordText::required($data->label, 'label', 'a identificação', 60),
            'recipient' => RecordText::optional($data->recipient, 'recipient', 'o destinatário', 120),
            'street' => RecordText::required($data->street, 'street', 'o logradouro', 160),
            'number' => RecordText::required($data->number, 'number', 'o número', 20),
            'complement' => RecordText::optional($data->complement, 'complement', 'o complemento', 80),
            'district' => RecordText::required($data->district, 'district', 'o bairro', 80),
            'city' => RecordText::required($data->city, 'city', 'a cidade', 120),
            'state' => BrazilianState::fromInput($data->state)->value,
            'postal_code' => PostalCode::fromString($data->postalCode)->digits,
            'is_active' => $data->isActive,
            'is_default' => $data->isDefault && $data->isActive,
        ];

        return DB::transaction(function () use ($company, $address, $attributes, $data): BuyerAddress {
            $company = $this->records->lockCompany($company);

            if ($address !== null) {
                $address = BuyerAddress::query()
                    ->where('buyer_company_id', $company->id)
                    ->lockForUpdate()
                    ->findOrFail($address->id);
            } else {
                $address = new BuyerAddress;
                $address->forceFill(['buyer_company_id' => $company->id]);
            }

            $creating = ! $address->exists;
            $unit = $this->records->unitOf($company, $data->buyerUnitId);
            $before = $creating ? null : $this->snapshot($address);

            $replacedDefaultIds = [];

            if ($attributes['is_default']) {
                $previous = BuyerAddress::query()
                    ->where('buyer_company_id', $company->id)
                    ->where('kind', $attributes['kind']->value)
                    ->where('is_default', true)
                    ->when(! $creating, static fn ($query) => $query->whereKeyNot($address->id))
                    ->lockForUpdate()
                    ->get();

                foreach ($previous as $other) {
                    $other->forceFill(['is_default' => false])->save();
                    $replacedDefaultIds[] = $other->id;
                }
            }

            $address->fill($attributes);
            $address->forceFill(['buyer_unit_id' => $unit?->id]);

            if (! $creating && ! $address->isDirty()) {
                return $address;
            }

            $address->save();

            $this->audit->record($creating ? 'buyer_address.created' : 'buyer_address.updated', $address, [
                'buyer_company_id' => $company->id,
                'before' => $before,
                'after' => $this->snapshot($address),
                'replaced_default_ids' => $replacedDefaultIds,
            ]);

            return $address;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BuyerAddress $address): array
    {
        $values = $address->only(self::AUDITED);
        $values['kind'] = $address->kind->value;

        return $values;
    }
}
