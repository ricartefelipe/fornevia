<?php

declare(strict_types=1);

namespace App\Services\Integrations\Imports;

use App\Actions\Buyers\SaveBuyerCompany;
use App\Data\Buyers\BuyerCompanyData;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\InvalidDocument;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use InvalidArgumentException;

/**
 * Empresas compradoras: razão social, nome fantasia, CNPJ, e-mail e
 * telefone. CNPJ repetido no arquivo ou já cadastrado é recusado (a
 * importação só cria).
 */
final readonly class CustomersImport implements ImportKind
{
    public function __construct(private SaveBuyerCompany $save) {}

    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Empresas compradoras';
    }

    public function permission(): Permission
    {
        return Permission::BuyersManage;
    }

    public function columns(): array
    {
        return ['razao_social', 'nome_fantasia', 'cnpj', 'email', 'telefone'];
    }

    public function validate(array $rows): array
    {
        $documents = [];

        foreach ($rows as $line => $row) {
            try {
                $documents[$line] = Cnpj::fromString($row['cnpj'] ?? '')->digits;
            } catch (InvalidArgumentException|InvalidDocument) {
                $documents[$line] = null;
            }
        }

        $existing = BuyerCompany::query()->whereIn('document', array_values(array_filter($documents)))->pluck('document')->all();
        $seen = [];
        /** @var array<int, list<string>> $errors */
        $errors = [];

        foreach ($rows as $line => $row) {
            /** @var list<string> $rowErrors */
            $rowErrors = [];
            $document = $documents[$line];

            if (($row['razao_social'] ?? '') === '' || mb_strlen($row['razao_social']) > 200) {
                $rowErrors[] = 'Razão social obrigatória (até 200 caracteres).';
            }

            if (($row['nome_fantasia'] ?? '') === '' || mb_strlen($row['nome_fantasia']) > 120) {
                $rowErrors[] = 'Nome fantasia obrigatório (até 120 caracteres).';
            }

            if ($document === null) {
                $rowErrors[] = 'CNPJ inválido.';
            } elseif (in_array($document, $existing, true)) {
                $rowErrors[] = 'CNPJ já cadastrado.';
            } elseif (isset($seen[$document])) {
                $rowErrors[] = "CNPJ repetido no arquivo (linha {$seen[$document]}).";
            } else {
                $seen[$document] = $line;
            }

            if (($row['email'] ?? '') !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
                $rowErrors[] = 'E-mail inválido.';
            }

            $errors[$line] = $rowErrors;
        }

        return $errors;
    }

    public function apply(array $row, array &$memo): string
    {
        return $this->save->handle(null, new BuyerCompanyData(
            legalName: $row['razao_social'],
            tradeName: $row['nome_fantasia'],
            document: Cnpj::fromString($row['cnpj']),
            email: ($row['email'] ?? '') !== '' ? $row['email'] : null,
            phone: ($row['telefone'] ?? '') !== '' ? $row['telefone'] : null,
        ))->id;
    }
}
