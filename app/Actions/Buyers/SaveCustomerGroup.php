<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Data\Buyers\CustomerGroupData;
use App\Domain\Buyers\Exceptions\CustomerGroupCodeTaken;
use App\Domain\Buyers\RecordText;
use App\Enums\Identity\Permission;
use App\Models\Buyers\CustomerGroup;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra ou altera um grupo de clientes. O código é único no tenant
 * (normalizado em maiúsculas). Código, nome e descrição não influenciam o
 * preço resolvido; a associação de empresas ao grupo é feita em
 * SaveBuyerCompany, que incrementa a versão de preços.
 */
final readonly class SaveCustomerGroup
{
    /** @var list<string> */
    private const array AUDITED = ['code', 'name', 'description'];

    private const string DUPLICATE_MESSAGE = 'Já existe um grupo de clientes com este código.';

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(?CustomerGroup $group, CustomerGroupData $data): CustomerGroup
    {
        $this->access->authorize(Permission::BuyersManage);

        $attributes = [
            'code' => RecordText::code($data->code, 'code', 'o código', 30),
            'name' => RecordText::required($data->name, 'name', 'o nome', 120),
            'description' => RecordText::optional($data->description, 'description', 'a descrição', 1000),
        ];

        try {
            return DB::transaction(function () use ($group, $attributes): CustomerGroup {
                $group = $group !== null
                    ? CustomerGroup::query()->lockForUpdate()->findOrFail($group->id)
                    : new CustomerGroup;
                $creating = ! $group->exists;

                $duplicate = CustomerGroup::query()
                    ->where('code', $attributes['code'])
                    ->when(! $creating, static fn ($query) => $query->whereKeyNot($group->id))
                    ->exists();

                if ($duplicate) {
                    throw new CustomerGroupCodeTaken(self::DUPLICATE_MESSAGE, ['field' => 'code']);
                }

                $before = $creating ? null : $group->only(self::AUDITED);
                $group->fill($attributes);

                if (! $creating && ! $group->isDirty()) {
                    return $group;
                }

                $group->save();

                $this->audit->record($creating ? 'customer_group.created' : 'customer_group.updated', $group, [
                    'before' => $before,
                    'after' => $group->only(self::AUDITED),
                ]);

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CustomerGroupCodeTaken(self::DUPLICATE_MESSAGE, ['field' => 'code']);
        }
    }
}
