<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Catalog\Concerns;

use App\Enums\Identity\Permission;
use App\Support\Auth\Access;

/**
 * Permissões usadas apenas para adaptar a tela (exibir ou ocultar ações e
 * dados internos). A autorização efetiva é sempre feita pelas Actions.
 */
trait ChecksCatalogPermissions
{
    protected function canManageCatalog(): bool
    {
        return app(Access::class)->can(Permission::CatalogManage);
    }

    protected function canViewCosts(): bool
    {
        return app(Access::class)->can(Permission::CatalogViewCosts);
    }

    /**
     * Escapa curingas de LIKE em termos de busca digitados pelo usuário.
     */
    protected static function likePattern(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }
}
