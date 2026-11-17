<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Actions\Identity\CreateOrganization;
use App\Data\Identity\CreateOrganizationData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Cnpj;
use App\Http\Middleware\EstablishRequestContext;
use App\Models\Identity\User;
use App\Models\Platform\Plan;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Cadastrar distribuidora')]
final class CreateOrganizationForm extends Component
{
    public string $legalName = '';

    public string $tradeName = '';

    public string $document = '';

    public string $planCode = '';

    public string $email = '';

    public string $phone = '';

    public function mount(): void
    {
        $this->planCode = (string) Plan::query()->where('is_active', true)->where('is_public', true)
            ->orderBy('sort_order')->value('code');
    }

    public function save(CreateOrganization $create): void
    {
        $this->validate([
            'legalName' => ['required', 'string', 'max:200'],
            'tradeName' => ['required', 'string', 'max:160'],
            'document' => ['required', 'string', 'max:18'],
            'planCode' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:20'],
        ], [], [
            'legalName' => 'razão social',
            'tradeName' => 'nome fantasia',
            'document' => 'CNPJ',
            'planCode' => 'plano',
        ]);

        /** @var User $user */
        $user = auth()->user();

        try {
            $membership = $create->handle($user, new CreateOrganizationData(
                legalName: trim($this->legalName),
                tradeName: trim($this->tradeName),
                document: Cnpj::fromString($this->document),
                planCode: $this->planCode,
                email: $this->email !== '' ? $this->email : null,
                phone: $this->phone !== '' ? $this->phone : null,
            ));
        } catch (BusinessRuleViolation $e) {
            $this->addError($e->errorCode() === 'plan_unavailable' ? 'planCode' : 'document', $e->getMessage());

            return;
        }

        session()->migrate(true);
        session()->put(EstablishRequestContext::SESSION_KEY, EstablishRequestContext::sessionPayload($membership));
        session()->flash('status', 'Distribuidora cadastrada. O período de avaliação começou.');

        $this->redirectRoute('dashboard');
    }

    public function render(): View
    {
        return view('livewire.onboarding.create-organization-form', [
            'plans' => Plan::query()->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get(),
        ]);
    }
}
