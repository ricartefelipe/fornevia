<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerContact;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Remove um contato da empresa compradora (contatos não têm situação
 * ativa/inativa). Os dados removidos ficam registrados na auditoria.
 */
final readonly class DeleteBuyerContact
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private BuyerRecords $records,
    ) {}

    public function handle(BuyerCompany $company, BuyerContact $contact): void
    {
        $this->access->authorize(Permission::BuyersManage);

        DB::transaction(function () use ($company, $contact): void {
            $company = $this->records->lockCompany($company);
            $locked = BuyerContact::query()
                ->where('buyer_company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($contact->id);

            $snapshot = $locked->only(['name', 'email', 'phone', 'position', 'is_primary']);
            $locked->delete();

            $this->audit->record('buyer_contact.deleted', $locked, [
                'buyer_company_id' => $company->id,
                'before' => $snapshot,
            ]);
        });
    }
}
