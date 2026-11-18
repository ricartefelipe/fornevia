<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Actions\Buyers\DeleteBuyerContact;
use App\Actions\Buyers\SaveBuyerContact;
use App\Data\Buyers\BuyerContactData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Livewire\Staff\Buyers\Concerns\InteractsWithBuyerCompany;
use App\Models\Buyers\BuyerContact;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Contatos da empresa compradora (seção da página da empresa).
 */
final class BuyerContacts extends Component
{
    use InteractsWithBuyerCompany;

    private const array FIELDS = ['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'position' => 'position'];

    #[Locked]
    public ?string $editingId = null;

    public bool $formOpen = false;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $position = '';

    public bool $isPrimary = false;

    public ?string $message = null;

    public function mount(string $buyerId): void
    {
        $this->bindBuyer($buyerId);
    }

    public function create(): void
    {
        $this->cancel();
        $this->formOpen = true;
    }

    public function edit(string $contactId): void
    {
        $contact = $this->contact($contactId);

        $this->resetErrorBag();
        $this->editingId = $contact->id;
        $this->name = $contact->name;
        $this->email = (string) $contact->email;
        $this->phone = (string) $contact->phone;
        $this->position = (string) $contact->position;
        $this->isPrimary = $contact->is_primary;
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'formOpen', 'name', 'email', 'phone', 'position', 'isPrimary');
        $this->resetErrorBag();
    }

    public function save(SaveBuyerContact $save): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:80'],
            'isPrimary' => ['boolean'],
        ], [], ['name' => 'nome', 'email' => 'e-mail', 'phone' => 'telefone', 'position' => 'cargo']);

        $wasEditing = $this->editingId !== null;

        try {
            $save->handle(
                $this->company(),
                $wasEditing ? $this->contact((string) $this->editingId) : null,
                new BuyerContactData(
                    name: $this->name,
                    email: $this->email,
                    phone: $this->phone,
                    position: $this->position,
                    isPrimary: $this->isPrimary,
                ),
            );
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, self::FIELDS, 'name');

            return;
        }

        $this->cancel();
        $this->message = $wasEditing ? 'Contato atualizado.' : 'Contato cadastrado.';
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function delete(string $contactId, DeleteBuyerContact $delete): void
    {
        $delete->handle($this->company(), $this->contact($contactId));

        if ($this->editingId === $contactId) {
            $this->cancel();
        }

        $this->message = 'Contato removido.';
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function render(): View
    {
        return view('livewire.staff.buyers.buyer-contacts', [
            'contacts' => BuyerContact::query()
                ->where('buyer_company_id', $this->buyerId)
                ->orderByDesc('is_primary')
                ->orderBy('name')
                ->get(),
            'canManage' => $this->canManage(),
        ]);
    }

    private function contact(string $contactId): BuyerContact
    {
        return BuyerContact::query()
            ->where('buyer_company_id', $this->buyerId)
            ->findOrFail(UntrustedId::uuid($contactId, BuyerContact::class));
    }
}
