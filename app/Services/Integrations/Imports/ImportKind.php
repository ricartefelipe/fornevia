<?php

declare(strict_types=1);

namespace App\Services\Integrations\Imports;

use App\Enums\Identity\Permission;

/**
 * Tipo de importação CSV (RF-113): colunas, permissão exigida, validação
 * de todas as linhas (com duplicidade no arquivo e no cadastro) e
 * aplicação de uma linha válida pelas Actions do módulo.
 */
interface ImportKind
{
    public function key(): string;

    public function label(): string;

    public function permission(): Permission;

    /** @return list<string> colunas obrigatórias */
    public function columns(): array;

    /** @return list<string> colunas aceitas quando presentes no arquivo */
    public function optionalColumns(): array;

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, list<string>> linha => erros (vazia quando válida)
     */
    public function validate(array $rows): array;

    /**
     * Completa as linhas com o que a prévia resolveu e a aplicação deve usar
     * (por exemplo, a tabela de preço conferida), guardado junto da linha.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, string>>
     */
    public function prepare(array $rows): array;

    /**
     * Aplica uma linha já validada; devolve o id do registro criado ou
     * alterado. Lança BusinessRuleViolation se a regra recusar agora.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string>  $memo  ids criados por esta importação (reaproveitamento entre linhas)
     */
    public function apply(array $row, array &$memo): string;
}
