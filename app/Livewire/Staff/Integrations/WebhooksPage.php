<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Integrations;

use App\Actions\Integrations\ManageWebhookEndpoints;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Integrations\WebhookDelivery;
use App\Models\Integrations\WebhookEndpoint;
use App\Support\Http\UntrustedId;
use App\Support\Integrations\WebhookEvents;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Webhooks enviados (RF-111): endereços HTTPS públicos, eventos assinados
 * com segredo mostrado uma única vez, envio de teste, reativação e as
 * entregas recentes com o resultado de cada tentativa.
 */
#[Layout('layouts.app')]
#[Title('Webhooks')]
final class WebhooksPage extends Component
{
    public const int RECENT_DELIVERIES = 50;

    public string $url = '';

    /** @var list<string> */
    public array $events = [];

    #[Locked]
    public ?string $issuedSecret = null;

    public string $message = '';

    public function create(ManageWebhookEndpoints $endpoints): void
    {
        $this->run(function () use ($endpoints): void {
            [, $this->issuedSecret] = $endpoints->create($this->url, array_values(array_map('strval', $this->events)));
            $this->reset('url', 'events');
            $this->message = 'Endpoint cadastrado.';
        });
    }

    public function toggle(string $endpointId, ManageWebhookEndpoints $endpoints): void
    {
        $this->run(function () use ($endpoints, $endpointId): void {
            $endpoint = WebhookEndpoint::query()->findOrFail(UntrustedId::uuid($endpointId, WebhookEndpoint::class));
            $endpoints->update($endpoint->id, $endpoint->url, $endpoint->events, ! $endpoint->is_active);
            $this->message = $endpoint->is_active ? 'Endpoint desativado.' : 'Endpoint ativado.';
        });
    }

    public function rotate(string $endpointId, ManageWebhookEndpoints $endpoints): void
    {
        $this->run(function () use ($endpoints, $endpointId): void {
            $this->issuedSecret = $endpoints->rotateSecret($endpointId);
            $this->message = 'Segredo trocado; o anterior deixou de assinar as entregas.';
        });
    }

    public function ping(string $endpointId, ManageWebhookEndpoints $endpoints): void
    {
        $this->run(function () use ($endpoints, $endpointId): void {
            $endpoints->ping($endpointId);
            $this->message = 'Envio de teste agendado; o resultado aparece nas entregas recentes.';
        });
    }

    public function delete(string $endpointId, ManageWebhookEndpoints $endpoints): void
    {
        $this->run(function () use ($endpoints, $endpointId): void {
            $endpoints->delete($endpointId);
            $this->message = 'Endpoint excluído.';
        });
    }

    public function render(): View
    {
        return view('livewire.staff.integrations.webhooks-page', [
            'endpoints' => WebhookEndpoint::query()->orderBy('created_at')->get(),
            'deliveries' => WebhookDelivery::query()->with('endpoint')->orderByDesc('created_at')->limit(self::RECENT_DELIVERIES)->get(),
            'eventOptions' => array_map(static fn (array $e): string => $e['label'], WebhookEvents::EVENTS),
        ]);
    }

    /**
     * @param  Closure(): void  $action
     */
    private function run(Closure $action): void
    {
        $this->resetErrorBag();
        $this->issuedSecret = null;
        $this->message = '';

        try {
            $action();
        } catch (BusinessRuleViolation $e) {
            $field = $e->details()['field'] ?? 'form';
            $this->addError(in_array($field, ['url', 'events'], true) ? $field : 'form', $e->getMessage());
        }
    }
}
