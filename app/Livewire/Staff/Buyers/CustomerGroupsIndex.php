<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Actions\Buyers\DeleteCustomerGroup;
use App\Actions\Buyers\SaveCustomerGroup;
use App\Data\Buyers\CustomerGroupData;
use App\Domain\Buyers\Exceptions\CustomerGroupInUse;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Support\Auth\Access;
use App\Support\Auth\SalesPortfolio;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Grupos de clientes (segmentação para tabelas de preço e catálogos
 * privados): cadastro, edição e exclusão de grupos sem uso.
 */
#[Layout('layouts.app')]
#[Title('Grupos de clientes')]
final class CustomerGroupsIndex extends Component
{
    use WithPagination;

    public const int PER_PAGE = 25;

    private const array FIELDS = ['code' => 'code', 'name' => 'name', 'description' => 'description'];

    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public ?string $message = null;

    public ?string $failure = null;

    public function edit(string $groupId): void
    {
        $group = $this->group($groupId);

        $this->resetErrorBag();
        $this->failure = null;
        $this->editingId = $group->id;
        $this->code = $group->code;
        $this->name = $group->name;
        $this->description = (string) $group->description;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'code', 'name', 'description');
        $this->resetErrorBag();
    }

    public function save(SaveCustomerGroup $save): void
    {
        $this->failure = null;
        $this->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [], ['code' => 'código', 'name' => 'nome', 'description' => 'descrição']);

        $wasEditing = $this->editingId !== null;

        try {
            $save->handle(
                $wasEditing ? $this->group((string) $this->editingId) : null,
                new CustomerGroupData(code: $this->code, name: $this->name, description: $this->description),
            );
        } catch (BusinessRuleViolation $e) {
            $field = $e->details()['field'] ?? null;
            $this->addError(is_string($field) && isset(self::FIELDS[$field]) ? self::FIELDS[$field] : 'code', $e->getMessage());

            return;
        }

        $this->cancel();
        $this->message = $wasEditing ? 'Grupo atualizado.' : 'Grupo cadastrado.';
    }

    public function delete(string $groupId, DeleteCustomerGroup $delete): void
    {
        $group = $this->group($groupId);
        $this->message = null;

        try {
            $delete->handle($group);
        } catch (CustomerGroupInUse $e) {
            $this->failure = $e->getMessage();

            return;
        }

        if ($this->editingId === $group->id) {
            $this->cancel();
        }

        $this->failure = null;
        $this->message = "Grupo {$group->code} excluído.";
    }

    public function render(): View
    {
        $groups = CustomerGroup::query()
            ->select('customer_groups.*')
            ->selectSub(
                app(SalesPortfolio::class)->companies(BuyerCompany::query())->selectRaw('count(*)')->whereColumn('buyer_companies.customer_group_id', 'customer_groups.id'),
                'companies_count',
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.staff.buyers.customer-groups-index', [
            'groups' => $groups,
            'canManage' => app(Access::class)->can(Permission::BuyersManage),
        ]);
    }

    private function group(string $groupId): CustomerGroup
    {
        return CustomerGroup::query()->findOrFail(UntrustedId::uuid($groupId, CustomerGroup::class));
    }
}
