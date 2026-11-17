<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Actions\Identity\SaveBranch;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Cnpj;
use App\Models\Identity\Branch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Filiais')]
final class BranchesIndex extends Component
{
    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $document = '';

    public string $city = '';

    public string $state = '';

    public bool $isActive = true;

    public ?string $message = null;

    public function edit(string $branchId): void
    {
        $branch = Branch::query()->findOrFail($branchId);
        $this->editingId = $branch->id;
        $this->code = $branch->code;
        $this->name = $branch->name;
        $this->document = (string) $branch->document;
        $this->city = (string) $branch->city;
        $this->state = (string) $branch->state;
        $this->isActive = $branch->is_active;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'code', 'name', 'document', 'city', 'state', 'isActive');
        $this->resetErrorBag();
    }

    public function save(SaveBranch $save): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash'],
            'name' => ['required', 'string', 'max:160'],
            'document' => ['nullable', 'string', 'max:18'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2'],
            'isActive' => ['boolean'],
        ], [], ['code' => 'código', 'name' => 'nome', 'document' => 'CNPJ', 'city' => 'cidade', 'state' => 'UF']);

        $duplicate = Branch::query()->where('code', mb_strtoupper($this->code))
            ->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))
            ->exists();

        if ($duplicate) {
            $this->addError('code', 'Já existe uma filial com este código.');

            return;
        }

        $wasEditing = $this->editingId !== null;

        try {
            $save->handle($wasEditing ? Branch::query()->findOrFail($this->editingId) : null, [
                'code' => $this->code,
                'name' => $this->name,
                'document' => $this->document !== '' ? Cnpj::fromString($this->document)->digits : null,
                'city' => $this->city !== '' ? $this->city : null,
                'state' => $this->state !== '' ? $this->state : null,
                'is_active' => $this->isActive,
            ]);
        } catch (BusinessRuleViolation $e) {
            $this->addError($e->errorCode() === 'invalid_document' ? 'document' : 'code', $e->getMessage());

            return;
        }

        $this->cancel();
        $this->message = $wasEditing ? 'Filial atualizada.' : 'Filial cadastrada.';
    }

    public function render(): View
    {
        return view('livewire.staff.branches-index', [
            'branches' => Branch::query()->orderByDesc('is_headquarters')->orderBy('name')->get(),
        ]);
    }
}
