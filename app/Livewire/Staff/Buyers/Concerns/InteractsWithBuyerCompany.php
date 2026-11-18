<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers\Concerns;

use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Livewire\Attributes\Locked;

/**
 * Seções da página da empresa compradora (componentes filhos). A empresa
 * é identificada por propriedade bloqueada e recarregada no escopo do
 * tenant a cada ação; identificadores de registros filhos recebidos do
 * cliente são restritos à mesma empresa.
 */
trait InteractsWithBuyerCompany
{
    #[Locked]
    public string $buyerId = '';

    /**
     * Evento emitido após qualquer alteração no cadastro da empresa
     * (atualiza o histórico e listas dependentes, como as unidades).
     */
    public const string UPDATED_EVENT = 'buyer-updated';

    protected function bindBuyer(mixed $buyerId): void
    {
        $this->buyerId = BuyerCompany::query()->findOrFail(UntrustedId::uuid($buyerId, BuyerCompany::class))->id;
    }

    protected function company(): BuyerCompany
    {
        return BuyerCompany::query()->findOrFail($this->buyerId);
    }

    protected function canManage(): bool
    {
        return app(Access::class)->can(Permission::BuyersManage);
    }

    /**
     * Associa a mensagem da regra de negócio ao campo do formulário.
     *
     * @param  array<string, string>  $fields  campo da exceção => propriedade
     */
    protected function addViolation(BusinessRuleViolation $violation, array $fields, string $fallback): void
    {
        $field = $violation->details()['field'] ?? null;

        $this->addError(is_string($field) && isset($fields[$field]) ? $fields[$field] : $fallback, $violation->getMessage());
    }
}
