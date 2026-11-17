<?php

declare(strict_types=1);

namespace App\Livewire\Platform;

use App\Actions\Platform\ChangeOrganizationStatus;
use App\Enums\Identity\OrganizationStatus;
use App\Enums\Platform\SubscriptionStatus;
use App\Models\Identity\Organization;
use App\Models\Identity\User;
use App\Models\Platform\Subscription;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Visão da plataforma sobre os distribuidores: apenas dados de contrato,
 * nunca dados comerciais dos tenants.
 */
#[Layout('layouts.app')]
#[Title('Organizações')]
final class OrganizationsIndex extends Component
{
    use WithPagination;

    public string $reason = '';

    public ?string $message = null;

    public function suspend(string $organizationId, ChangeOrganizationStatus $change): void
    {
        $this->change($organizationId, OrganizationStatus::Suspended, $change);
    }

    public function reactivate(string $organizationId, ChangeOrganizationStatus $change): void
    {
        $this->change($organizationId, OrganizationStatus::Active, $change);
    }

    public function render(): View
    {
        $organizations = Organization::query()->orderBy('trade_name')->paginate(25);
        $subscriptions = Subscription::query()
            ->with('plan')
            ->whereIn('organization_id', $organizations->pluck('id'))
            ->where('status', '<>', SubscriptionStatus::Cancelled->value)
            ->get()
            ->keyBy('organization_id');

        return view('livewire.platform.organizations-index', [
            'organizations' => $organizations,
            'subscriptions' => $subscriptions,
        ]);
    }

    private function change(string $organizationId, OrganizationStatus $status, ChangeOrganizationStatus $change): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            $change->handle($actor, Organization::query()->findOrFail(UntrustedId::uuid($organizationId, Organization::class)), $status, $this->reason);
        } catch (InvalidArgumentException) {
            $this->addError('reason', 'Informe a justificativa (mínimo de 5 caracteres).');

            return;
        }

        $this->reset('reason');
        $this->resetErrorBag();
        $this->message = 'Situação atualizada.';
    }
}
