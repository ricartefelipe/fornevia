<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Actions\Buyers\SaveBuyerAddress;
use App\Data\Buyers\BuyerAddressData;
use App\Domain\Buyers\BrazilianState;
use App\Domain\Buyers\PostalCode;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Buyers\AddressKind;
use App\Livewire\Staff\Buyers\Concerns\InteractsWithBuyerCompany;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerUnit;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Endereços de cobrança e entrega da empresa compradora (seção da página
 * da empresa). Um endereço padrão ativo por tipo.
 */
final class BuyerAddresses extends Component
{
    use InteractsWithBuyerCompany;

    private const array FIELDS = [
        'label' => 'label', 'recipient' => 'recipient', 'street' => 'street', 'number' => 'number',
        'complement' => 'complement', 'district' => 'district', 'city' => 'city', 'state' => 'state',
        'postal_code' => 'postalCode', 'buyer_unit_id' => 'buyerUnitId',
    ];

    #[Locked]
    public ?string $editingId = null;

    public bool $formOpen = false;

    public string $kind = 'shipping';

    public string $label = '';

    public string $recipient = '';

    public string $postalCode = '';

    public string $street = '';

    public string $number = '';

    public string $complement = '';

    public string $district = '';

    public string $city = '';

    public string $state = '';

    public string $buyerUnitId = '';

    public bool $isDefault = false;

    public bool $isActive = true;

    public ?string $message = null;

    public function mount(string $buyerId): void
    {
        $this->bindBuyer($buyerId);
    }

    /**
     * Unidades cadastradas em outra seção passam a aparecer na seleção.
     */
    #[On('buyer-updated')]
    public function refreshUnits(): void {}

    public function create(): void
    {
        $this->cancel();
        $this->formOpen = true;
    }

    public function edit(string $addressId): void
    {
        $address = $this->address($addressId);

        $this->resetErrorBag();
        $this->editingId = $address->id;
        $this->kind = $address->kind->value;
        $this->label = $address->label;
        $this->recipient = (string) $address->recipient;
        $this->postalCode = PostalCode::format($address->postal_code);
        $this->street = $address->street;
        $this->number = $address->number;
        $this->complement = (string) $address->complement;
        $this->district = $address->district;
        $this->city = $address->city;
        $this->state = $address->state;
        $this->buyerUnitId = (string) $address->buyer_unit_id;
        $this->isDefault = $address->is_default;
        $this->isActive = $address->is_active;
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'formOpen', 'kind', 'label', 'recipient', 'postalCode', 'street', 'number',
            'complement', 'district', 'city', 'state', 'buyerUnitId', 'isDefault', 'isActive');
        $this->resetErrorBag();
    }

    public function save(SaveBuyerAddress $save): void
    {
        $this->validate([
            'kind' => ['required', Rule::enum(AddressKind::class)],
            'label' => ['required', 'string', 'max:60'],
            'recipient' => ['nullable', 'string', 'max:120'],
            'postalCode' => ['required', 'string', 'max:9'],
            'street' => ['required', 'string', 'max:160'],
            'number' => ['required', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:80'],
            'district' => ['required', 'string', 'max:80'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'size:2'],
            'buyerUnitId' => ['nullable', 'uuid'],
            'isDefault' => ['boolean'],
            'isActive' => ['boolean'],
        ], [], [
            'kind' => 'tipo', 'label' => 'identificação', 'recipient' => 'destinatário', 'postalCode' => 'CEP',
            'street' => 'logradouro', 'number' => 'número', 'complement' => 'complemento', 'district' => 'bairro',
            'city' => 'cidade', 'state' => 'UF', 'buyerUnitId' => 'unidade',
        ]);

        $wasEditing = $this->editingId !== null;

        try {
            $save->handle(
                $this->company(),
                $wasEditing ? $this->address((string) $this->editingId) : null,
                new BuyerAddressData(
                    kind: AddressKind::from($this->kind),
                    label: $this->label,
                    street: $this->street,
                    number: $this->number,
                    district: $this->district,
                    city: $this->city,
                    state: $this->state,
                    postalCode: $this->postalCode,
                    recipient: $this->recipient,
                    complement: $this->complement,
                    buyerUnitId: $this->buyerUnitId !== '' ? $this->buyerUnitId : null,
                    isDefault: $this->isDefault,
                    isActive: $this->isActive,
                ),
            );
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, self::FIELDS, 'label');

            return;
        }

        $this->cancel();
        $this->message = $wasEditing ? 'Endereço atualizado.' : 'Endereço cadastrado.';
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function render(): View
    {
        return view('livewire.staff.buyers.buyer-addresses', [
            'addresses' => BuyerAddress::query()
                ->with('unit')
                ->where('buyer_company_id', $this->buyerId)
                ->orderBy('kind')
                ->orderByDesc('is_active')
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->get(),
            'kinds' => collect(AddressKind::cases())->mapWithKeys(static fn (AddressKind $kind): array => [$kind->value => $kind->label()])->all(),
            'states' => BrazilianState::options(),
            'units' => $this->unitOptions(),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * Unidades ativas da empresa e, na edição, a unidade já vinculada (mesmo
     * que inativa).
     *
     * @return array<string, string>
     */
    private function unitOptions(): array
    {
        $selected = Str::isUuid($this->buyerUnitId) ? $this->buyerUnitId : null;

        return BuyerUnit::query()
            ->where('buyer_company_id', $this->buyerId)
            ->where(static function (Builder $query) use ($selected): void {
                $query->where('is_active', true);

                if ($selected !== null) {
                    $query->orWhere('id', $selected);
                }
            })
            ->orderBy('name')
            ->get()
            ->mapWithKeys(static fn (BuyerUnit $unit): array => [$unit->id => "{$unit->name} ({$unit->code})".($unit->is_active ? '' : ' · inativa')])
            ->all();
    }

    private function address(string $addressId): BuyerAddress
    {
        return BuyerAddress::query()
            ->where('buyer_company_id', $this->buyerId)
            ->findOrFail(UntrustedId::uuid($addressId, BuyerAddress::class));
    }
}
