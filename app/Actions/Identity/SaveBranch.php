<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Enums\Identity\Permission;
use App\Models\Identity\Branch;
use App\Services\Audit\AuditRecorder;
use App\Services\Platform\PlanLimits;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

final readonly class SaveBranch
{
    public function __construct(
        private Access $access,
        private PlanLimits $limits,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, document?: string|null, city?: string|null, state?: string|null, is_active?: bool}  $attributes
     */
    public function handle(?Branch $branch, array $attributes): Branch
    {
        $this->access->authorize(Permission::OrganizationManage);

        return DB::transaction(function () use ($branch, $attributes): Branch {
            if ($branch === null) {
                $this->limits->assertCanAddBranch();
                $branch = new Branch;
            } else {
                $branch = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            }

            $before = $branch->only(['code', 'name', 'is_active']);
            $branch->fill([
                'code' => mb_strtoupper(trim($attributes['code'])),
                'name' => trim($attributes['name']),
                'document' => $attributes['document'] ?? null,
                'city' => $attributes['city'] ?? null,
                'state' => isset($attributes['state']) ? mb_strtoupper($attributes['state']) : null,
                'is_active' => $branch->is_headquarters ? true : ($attributes['is_active'] ?? true),
            ]);
            $creating = ! $branch->exists;
            $branch->save();

            $this->audit->record($creating ? 'branch.created' : 'branch.updated', $branch, [
                'before' => $creating ? null : $before,
                'after' => $branch->only(['code', 'name', 'is_active']),
            ]);

            return $branch;
        });
    }
}
