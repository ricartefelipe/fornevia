<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Domain\Buyers\Exceptions\BuyerCompanyStatusUnchanged;
use App\Domain\Buyers\Justification;
use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Altera a situação comercial da empresa compradora (ativa, bloqueada,
 * inativa) com justificativa obrigatória registrada na auditoria.
 */
final readonly class ChangeBuyerCompanyStatus
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private BuyerRecords $records,
    ) {}

    public function handle(BuyerCompany $company, BuyerCompanyStatus $status, string $reason): BuyerCompany
    {
        $this->access->authorize(Permission::BuyersManage);

        $justification = Justification::fromString($reason);

        return DB::transaction(function () use ($company, $status, $justification): BuyerCompany {
            $locked = $this->records->lockCompany($company);
            $previous = $locked->status;

            if ($previous === $status) {
                throw new BuyerCompanyStatusUnchanged(
                    "A empresa já está com a situação \"{$status->label()}\".",
                    ['field' => 'status'],
                );
            }

            $locked->forceFill(['status' => $status])->save();

            $this->audit->record('buyer_company.status_changed', $locked, [
                'from' => $previous->value,
                'to' => $status->value,
            ], $justification->text);

            return $locked;
        });
    }
}
