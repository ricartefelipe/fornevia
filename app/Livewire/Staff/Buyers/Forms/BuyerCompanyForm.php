<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers\Forms;

use App\Data\Buyers\BuyerCompanyData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\InvalidDocument;
use App\Models\Buyers\BuyerCompany;
use Livewire\Form;

/**
 * Campos do cadastro de empresa compradora, compartilhados entre a listagem
 * (cadastro) e a página da empresa (edição). Valida formato e tamanho; as
 * regras de negócio ficam em SaveBuyerCompany.
 */
final class BuyerCompanyForm extends Form
{
    /** Campo informado nas exceções de domínio => propriedade do formulário. */
    private const array FIELDS = [
        'legal_name' => 'legalName',
        'trade_name' => 'tradeName',
        'document' => 'document',
        'state_registration' => 'stateRegistration',
        'email' => 'email',
        'phone' => 'phone',
        'notes' => 'notes',
        'customer_group_id' => 'customerGroupId',
        'sales_rep_membership_id' => 'salesRepMembershipId',
    ];

    public string $legalName = '';

    public string $tradeName = '';

    public string $document = '';

    public string $stateRegistration = '';

    public string $email = '';

    public string $phone = '';

    public string $customerGroupId = '';

    public string $salesRepMembershipId = '';

    public string $notes = '';

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'legalName' => ['required', 'string', 'max:200'],
            'tradeName' => ['required', 'string', 'max:160'],
            'document' => ['required', 'string', 'max:18'],
            'stateRegistration' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:20'],
            'customerGroupId' => ['nullable', 'uuid'],
            'salesRepMembershipId' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'legalName' => 'razão social',
            'tradeName' => 'nome fantasia',
            'document' => 'CNPJ',
            'stateRegistration' => 'inscrição estadual',
            'email' => 'e-mail',
            'phone' => 'telefone',
            'customerGroupId' => 'grupo de clientes',
            'salesRepMembershipId' => 'representante',
            'notes' => 'observações',
        ];
    }

    public function fillFrom(BuyerCompany $company): void
    {
        $this->legalName = $company->legal_name;
        $this->tradeName = $company->trade_name;
        $this->document = Cnpj::format($company->document);
        $this->stateRegistration = (string) $company->state_registration;
        $this->email = (string) $company->email;
        $this->phone = (string) $company->phone;
        $this->customerGroupId = (string) $company->customer_group_id;
        $this->salesRepMembershipId = (string) $company->sales_rep_membership_id;
        $this->notes = (string) $company->notes;
    }

    /**
     * Valida o formulário e monta os dados para a Action. Retorna null (com o
     * erro associado ao campo) quando o CNPJ é inválido.
     */
    public function toData(): ?BuyerCompanyData
    {
        $this->validate();

        try {
            $document = Cnpj::fromString($this->document);
        } catch (InvalidDocument $e) {
            $this->addError('document', $e->getMessage());

            return null;
        }

        return new BuyerCompanyData(
            legalName: $this->legalName,
            tradeName: $this->tradeName,
            document: $document,
            stateRegistration: $this->stateRegistration,
            email: $this->email,
            phone: $this->phone,
            notes: $this->notes,
            customerGroupId: $this->customerGroupId !== '' ? $this->customerGroupId : null,
            salesRepMembershipId: $this->salesRepMembershipId !== '' ? $this->salesRepMembershipId : null,
        );
    }

    /**
     * Associa a mensagem de uma regra de negócio ao campo correspondente.
     */
    public function addViolation(BusinessRuleViolation $violation): void
    {
        $field = $violation->details()['field'] ?? null;

        $this->addError(is_string($field) && isset(self::FIELDS[$field]) ? self::FIELDS[$field] : 'legalName', $violation->getMessage());
    }
}
