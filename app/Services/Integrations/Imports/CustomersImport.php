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
        $documents = array_map(static fn (array $row): ?string => self::document($row['cnpj'] ?? ''), $rows);
        $existing = BuyerCompany::query()->whereIn('document', array_values(array_filter($documents)))->pluck('document')->all();
        $seen = [];
        /** @var array<int, list<string>> $errors */
        $errors = [];

        foreach ($rows as $line => $row) {
            $document = $documents[$line];
            $errors[$line] = [...self::nameErrors($row), ...self::documentErrors($document, $existing, $seen), ...self::emailErrors($row)];

            if ($document !== null && ! isset($seen[$document])) {
                $seen[$document] = $line;
            }
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

    private static function document(string $value): ?string
    {
        try {
            return Cnpj::fromString($value)->digits;
        } catch (InvalidArgumentException|InvalidDocument) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private static function nameErrors(array $row): array
    {
        $errors = [];

        if (($row['razao_social'] ?? '') === '' || mb_strlen($row['razao_social']) > 200) {
            $errors[] = 'Razão social obrigatória (até 200 caracteres).';
        }

        if (($row['nome_fantasia'] ?? '') === '' || mb_strlen($row['nome_fantasia']) > 120) {
            $errors[] = 'Nome fantasia obrigatório (até 120 caracteres).';
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private static function emailErrors(array $row): array
    {
        $invalid = ($row['email'] ?? '') !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false;

        return $invalid ? ['E-mail inválido.'] : [];
    }

    /**
     * @param  array<mixed>  $existing  CNPJs já cadastrados
     * @param  array<string, int>  $seen  CNPJ => primeira linha do arquivo
     * @return list<string>
     */
    private static function documentErrors(?string $document, array $existing, array $seen): array
    {
        return match (true) {
            $document === null => ['CNPJ inválido.'],
            in_array($document, $existing, true) => ['CNPJ já cadastrado.'],
            isset($seen[$document]) => ["CNPJ repetido no arquivo (linha {$seen[$document]})."],
            default => [],
        };
    }
}
