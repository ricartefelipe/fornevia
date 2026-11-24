<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Integrations;

use App\Actions\Integrations\ManageApiClients;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Integrations\ApiClient;
use App\Support\Integrations\ApiAbilities;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Clientes da API (RF-110): permissões concedidas a cada sistema externo,
 * tokens com prazo, mostrados uma única vez, e revogação imediata.
 */
#[Layout('layouts.app')]
#[Title('Integrações')]
final class ApiClientsPage extends Component
{
    public string $name = '';

    /** @var list<string> */
    public array $permissions = [];

    public int $validDays = 90;

    #[Locked]
    public ?string $issuedToken = null;

    public string $message = '';

    public function create(ManageApiClients $clients): void
    {
        $this->run(function () use ($clients): void {
            [, $this->issuedToken] = $clients->create($this->name, array_values(array_map('strval', $this->permissions)), $this->validDays);
            $this->reset('name', 'permissions', 'validDays');
            $this->message = 'Cliente criado.';
        });
    }

    public function newToken(string $clientId, ManageApiClients $clients): void
    {
        $this->run(function () use ($clients, $clientId): void {
            $this->issuedToken = $clients->newToken($clientId, $this->validDays);
            $this->message = 'Novo token emitido; os anteriores continuam válidos até o prazo ou a revogação.';
        });
    }

    public function revokeToken(int $tokenId, ManageApiClients $clients): void
    {
        $this->run(function () use ($clients, $tokenId): void {
            $clients->revokeToken($tokenId);
            $this->message = 'Token revogado.';
        });
    }

    public function revoke(string $clientId, ManageApiClients $clients): void
    {
        $this->run(function () use ($clients, $clientId): void {
            $clients->revoke($clientId);
            $this->message = 'Cliente revogado; os tokens dele deixaram de valer.';
        });
    }

    public function render(): View
    {
        return view('livewire.staff.integrations.api-clients-page', [
            'clients' => ApiClient::query()->with(['tokens' => static fn ($q) => $q->orderByDesc('created_at')])
                ->orderByRaw('revoked_at is not null')->orderBy('name')->get(),
            'abilityOptions' => ApiAbilities::options(),
            'apiUrl' => url('/api/v1'),
        ]);
    }

    /**
     * @param  Closure(): void  $action
     */
    private function run(Closure $action): void
    {
        $this->resetErrorBag();
        $this->issuedToken = null;
        $this->message = '';

        try {
            $action();
        } catch (BusinessRuleViolation $e) {
            $field = $e->details()['field'] ?? 'form';
            $this->addError(match ($field) {
                'valid_days' => 'validDays',
                'name', 'permissions' => $field,
                default => 'form',
            }, $e->getMessage());
        }
    }
}
