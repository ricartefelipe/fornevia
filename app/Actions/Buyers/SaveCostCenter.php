<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Data\Buyers\CostCenterData;
use App\Domain\Buyers\Exceptions\CostCenterCodeTaken;
use App\Domain\Buyers\RecordText;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CostCenter;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra ou altera um centro de custo da empresa compradora. O código é
 * único dentro da empresa e a unidade (opcional) precisa ser da mesma
 * empresa.
 */
final readonly class SaveCostCenter
{
    /** @var list<string> */
    private const array AUDITED = ['code', 'name', 'buyer_unit_id', 'is_active'];

    private const string DUPLICATE_MESSAGE = 'Já existe um centro de custo com este código nesta empresa.';

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private BuyerRecords $records,
    ) {}

    public function handle(BuyerCompany $company, ?CostCenter $costCenter, CostCenterData $data): CostCenter
    {
        $this->access->authorize(Permission::BuyersManage);

        $attributes = [
            'code' => RecordText::code($data->code, 'code', 'o código', 30),
            'name' => RecordText::required($data->name, 'name', 'o nome', 120),
            'is_active' => $data->isActive,
        ];

        try {
            return DB::transaction(function () use ($company, $costCenter, $attributes, $data): CostCenter {
                $company = $this->records->company($company);

                if ($costCenter !== null) {
                    $costCenter = CostCenter::query()
                        ->where('buyer_company_id', $company->id)
                        ->lockForUpdate()
                        ->findOrFail($costCenter->id);
                } else {
                    $costCenter = new CostCenter;
                    $costCenter->forceFill(['buyer_company_id' => $company->id]);
                }

                $creating = ! $costCenter->exists;
                $unit = $this->records->unitOf($company, $data->buyerUnitId);

                $duplicate = CostCenter::query()
                    ->where('buyer_company_id', $company->id)
                    ->where('code', $attributes['code'])
                    ->when(! $creating, static fn ($query) => $query->whereKeyNot($costCenter->id))
                    ->exists();

                if ($duplicate) {
                    throw new CostCenterCodeTaken(self::DUPLICATE_MESSAGE, ['field' => 'code']);
                }

                $before = $creating ? null : $costCenter->only(self::AUDITED);
                $costCenter->fill($attributes);
                $costCenter->forceFill(['buyer_unit_id' => $unit?->id]);

                if (! $creating && ! $costCenter->isDirty()) {
                    return $costCenter;
                }

                $costCenter->save();

                $this->audit->record($creating ? 'cost_center.created' : 'cost_center.updated', $costCenter, [
                    'buyer_company_id' => $company->id,
                    'before' => $before,
                    'after' => $costCenter->only(self::AUDITED),
                ]);

                return $costCenter;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CostCenterCodeTaken(self::DUPLICATE_MESSAGE, ['field' => 'code']);
        }
    }
}
