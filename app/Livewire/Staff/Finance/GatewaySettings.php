<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Finance;

use App\Actions\Finance\Staff\SavePaymentGatewaySettings;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Finance\PaymentGatewayKind;
use App\Support\Auth\Access;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Gateway de pagamento do distribuidor: simulador ou Asaas, chave de API
 * (nunca exibida de volta) e token do webhook, mostrado uma única vez.
 */
#[Layout('layouts.app')]
#[Title('Gateway de pagamento')]
final class GatewaySettings extends Component
{
    public string $gateway = 'simulator';

    public string $apiKey = '';

    public bool $newToken = false;

    #[Locked]
    public ?string $issuedToken = null;

    public string $message = '';

    public function mount(): void
    {
        $this->gateway = (string) (DB::table('payment_gateway_settings')->value('gateway') ?? 'simulator');
    }

    public function save(SavePaymentGatewaySettings $save): void
    {
        $this->resetErrorBag();
        $this->issuedToken = null;

        try {
            $this->issuedToken = $save->handle(PaymentGatewayKind::tryFrom($this->gateway) ?? PaymentGatewayKind::Simulator, $this->apiKey, $this->newToken);
        } catch (BusinessRuleViolation $e) {
            $this->addError('apiKey', $e->getMessage());

            return;
        }

        $this->reset('apiKey', 'newToken');
        $this->message = 'Gateway atualizado.';
    }

    public function render(Access $access): View
    {
        $settings = DB::table('payment_gateway_settings')->first(['gateway', 'api_key_encrypted', 'webhook_token_hash']);

        return view('livewire.staff.finance.gateway-settings', [
            'kinds' => collect(PaymentGatewayKind::cases())->mapWithKeys(static fn (PaymentGatewayKind $k): array => [$k->value => $k->label()])->all(),
            'hasKey' => $settings !== null && $settings->api_key_encrypted !== null,
            'hasToken' => $settings !== null && $settings->webhook_token_hash !== null,
            'webhookUrl' => route('webhooks.asaas', $access->membership()->tenant_id),
        ]);
    }
}
