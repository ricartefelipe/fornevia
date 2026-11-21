<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Data\Buyers\BuyerUnitData;
use App\Domain\Buyers\Exceptions\BuyerUnitCodeTaken;
use App\Domain\Buyers\RecordText;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra ou altera uma unidade (obra, loja, planta) da empresa
 * compradora. O código é único dentro da empresa.
 */
final readonly class SaveBuyerUnit
{
    /** @var list<string> */
    private const array AUDITED = ['code', 'name', 'is_active'];

    private const string DUPLICATE_MESSAGE = 'Já existe uma unidade com este código nesta empresa.';

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private BuyerRecords $records,
    ) {}

    public function handle(BuyerCompany $company, ?BuyerUnit $unit, BuyerUnitData $data): BuyerUnit
    {
        $this->access->authorize(Permission::BuyersManage);

        $attributes = [
            'code' => RecordText::code($data->code, 'code', 'o código', 20),
            'name' => RecordText::required($data->name, 'name', 'o nome', 120),
            'is_active' => $data->isActive,
        ];

        try {
            return DB::transaction(function () use ($company, $unit, $attributes): BuyerUnit {
                $company = $this->records->company($company);

                if ($unit !== null) {
                    $unit = BuyerUnit::query()
                        ->where('buyer_company_id', $company->id)
                        ->lockForUpdate()
                        ->findOrFail($unit->id);
                } else {
                    $unit = new BuyerUnit;
                    $unit->forceFill(['buyer_company_id' => $company->id]);
                }

                $creating = ! $unit->exists;

                $duplicate = BuyerUnit::query()
                    ->where('buyer_company_id', $company->id)
                    ->where('code', $attributes['code'])
                    ->when(! $creating, static fn ($query) => $query->whereKeyNot($unit->id))
                    ->exists();

                if ($duplicate) {
                    throw new BuyerUnitCodeTaken(self::DUPLICATE_MESSAGE, ['field' => 'code']);
                }

                $before = $creating ? null : $unit->only(self::AUDITED);
                $unit->fill($attributes);

                if (! $creating && ! $unit->isDirty()) {
                    return $unit;
                }

                $unit->save();

                $this->audit->record($creating ? 'buyer_unit.created' : 'buyer_unit.updated', $unit, [
                    'buyer_company_id' => $company->id,
                    'before' => $before,
                    'after' => $unit->only(self::AUDITED),
                ]);

                return $unit;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BuyerUnitCodeTaken(self::DUPLICATE_MESSAGE, ['field' => 'code']);
        }
    }
}
