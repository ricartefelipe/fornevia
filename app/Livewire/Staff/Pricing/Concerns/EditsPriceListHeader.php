<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Pricing\Concerns;

use App\Actions\Pricing\SavePriceList;
use App\Data\Pricing\PriceListData;
use App\Enums\Pricing\PriceListScope;
use App\Models\Pricing\PriceList;
use App\Support\Validation\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Campos do cabeçalho de tabela de preço (criação e edição): validação de
 * formato na tela e montagem do DTO; as regras de negócio ficam em
 * SavePriceList.
 *
 * @mixin Component
 */
trait EditsPriceListHeader
{
    public string $code = '';

    public string $name = '';

    public string $scope = 'default';

    public string $customerGroupId = '';

    public string $buyerCompanyId = '';

    public string $companySearch = '';

    public string $priority = '0';

    public string $validFrom = '';

    public string $validUntil = '';

    /**
     * Campos das violações de SavePriceList => propriedades do componente.
     *
     * @return array<string, string>
     */
    protected function headerFields(): array
    {
        return [
            'code' => 'code',
            'name' => 'name',
            'priority' => 'priority',
            'scope' => 'scope',
            'customer_group_id' => 'customerGroupId',
            'buyer_company_id' => 'buyerCompanyId',
        ];
    }

    protected function validateHeader(): PriceListData
    {
        $this->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::enum(PriceListScope::class)],
            'customerGroupId' => ['nullable', 'required_if:scope,group', 'uuid'],
            'buyerCompanyId' => ['nullable', 'required_if:scope,customer', 'uuid'],
            'priority' => ['required', 'integer', 'between:-'.SavePriceList::MAX_PRIORITY.','.SavePriceList::MAX_PRIORITY],
            'validFrom' => ['required', ...BusinessDate::rules()],
            'validUntil' => ['nullable', ...BusinessDate::rules(), 'after_or_equal:validFrom'],
        ], [
            'customerGroupId.required_if' => 'Selecione o grupo de clientes da tabela.',
            'buyerCompanyId.required_if' => 'Selecione a empresa compradora da tabela.',
            'code.regex' => 'Use letras, números, ponto, hífen ou sublinhado, começando por letra ou número.',
        ], [
            'code' => 'código',
            'name' => 'nome',
            'scope' => 'escopo',
            'customerGroupId' => 'grupo de clientes',
            'buyerCompanyId' => 'empresa compradora',
            'priority' => 'prioridade',
            'validFrom' => 'início da vigência',
            'validUntil' => 'término da vigência',
        ]);

        $scope = PriceListScope::from($this->scope);

        return new PriceListData(
            code: $this->code,
            name: $this->name,
            scope: $scope,
            validFrom: CarbonImmutable::parse($this->validFrom),
            validUntil: $this->validUntil !== '' ? CarbonImmutable::parse($this->validUntil) : null,
            priority: (int) $this->priority,
            customerGroupId: $scope === PriceListScope::Group ? $this->customerGroupId : null,
            buyerCompanyId: $scope === PriceListScope::Customer ? $this->buyerCompanyId : null,
        );
    }

    protected function fillHeader(PriceList $list): void
    {
        $this->code = $list->code;
        $this->name = $list->name;
        $this->scope = $list->scope->value;
        $this->customerGroupId = (string) $list->customer_group_id;
        $this->buyerCompanyId = (string) $list->buyer_company_id;
        $this->priority = (string) $list->priority;
        $this->validFrom = $list->valid_from->toDateString();
        $this->validUntil = (string) $list->valid_until?->toDateString();
    }
}
