<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Catalog\Support\CatalogText;
use App\Data\Catalog\CategoryData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\CategoryCycle;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Category;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra ou altera uma categoria do catálogo.
 *
 * O slug é gerado a partir do nome (único no tenant). O pai precisa ser do
 * mesmo tenant e não pode ser a própria categoria nem uma descendente. As
 * alterações da árvore são serializadas por lock consultivo do tenant, o que
 * impede que duas gravações simultâneas formem um ciclo.
 */
final readonly class SaveCategory
{
    private const int MAX_DEPTH = 64;

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private CurrentContext $context,
    ) {}

    public function handle(?Category $category, CategoryData $data): Category
    {
        $this->access->authorize(Permission::CatalogManage);

        $name = CatalogText::required($data->name, 'name', 120, 'Informe o nome da categoria (até 120 caracteres).');

        if ($data->sortOrder < 0 || $data->sortOrder > 100_000) {
            throw InvalidCatalogData::field('sort_order', 'A ordem de exibição deve estar entre 0 e 100.000.');
        }

        try {
            return DB::transaction(function () use ($category, $data, $name): Category {
                $this->lockTree();

                $category = $category !== null
                    ? Category::query()->lockForUpdate()->findOrFail($category->id)
                    : new Category;

                $parentId = null;

                if ($data->parentId !== null) {
                    $parent = Category::query()->findOrFail(UntrustedId::uuid($data->parentId, Category::class));

                    if ($category->exists && $this->isSelfOrDescendant($category->id, $parent->id)) {
                        throw CategoryCycle::create();
                    }

                    $parentId = $parent->id;
                }

                $creating = ! $category->exists;
                $before = $creating ? null : self::snapshot($category);
                $slug = ! $creating && $category->name === $name
                    ? $category->slug
                    : CatalogText::uniqueSlug(Category::query(), $name, 'categoria', 140, $creating ? null : $category->id);

                $category->forceFill([
                    'name' => $name,
                    'slug' => $slug,
                    'parent_id' => $parentId,
                    'sort_order' => $data->sortOrder,
                ]);

                if (! $creating && ! $category->isDirty()) {
                    return $category;
                }

                $category->save();

                $this->audit->record($creating ? 'category.created' : 'category.updated', $category, [
                    'before' => $before,
                    'after' => self::snapshot($category),
                ]);

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            throw CatalogIdentifierInUse::for('name', $name, 'Já existe uma categoria com este nome. Tente novamente ou escolha outro nome.');
        }
    }

    /**
     * Verdadeiro quando o candidato a pai é a própria categoria ou está
     * abaixo dela na árvore (o ancestral procurado aparece na cadeia).
     */
    private function isSelfOrDescendant(string $categoryId, string $candidateParentId): bool
    {
        $row = DB::selectOne(
            'with recursive ancestors (id, parent_id, depth) as (
                select id, parent_id, 1 from categories where id = ? and tenant_id = ?
                union all
                select c.id, c.parent_id, a.depth + 1
                from categories c
                join ancestors a on c.id = a.parent_id
                where c.tenant_id = ? and a.depth < ?
            )
            select exists (select 1 from ancestors where id = ?) as found',
            [$candidateParentId, $this->context->tenantId(), $this->context->tenantId(), self::MAX_DEPTH, $categoryId],
        );

        return (bool) ($row->found ?? false);
    }

    private function lockTree(): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['catalog.categories:'.$this->context->tenantId()]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(Category $category): array
    {
        return [
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
            'sort_order' => $category->sort_order,
        ];
    }
}
