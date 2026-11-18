<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Domain\Buyers\Justification;
use App\Domain\Credit\Exceptions\CreditAccountUnchanged;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Credit\CreditAccount;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Altera a tolerância de vencidos (dias após o vencimento em que títulos
 * em aberto ainda não bloqueiam novas compras a prazo, RN-080), com
 * justificativa registrada na auditoria.
 */
final readonly class ChangeOverdueTolerance
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private CreditAccounts $accounts,
    ) {}

    public function handle(BuyerCompany $company, int $days, string $reason): CreditAccount
    {
        $this->access->authorize(Permission::CreditManage);

        $justification = Justification::fromString($reason);
        $days = $this->accounts->tolerance($days);

        return DB::transaction(function () use ($company, $days, $justification): CreditAccount {
            $account = $this->accounts->lock($company);
            $this->accounts->actorUserId();
            $previous = $account->overdue_tolerance_days;

            if ($previous === $days) {
                throw new CreditAccountUnchanged("A tolerância já é de {$days} dia(s).", ['field' => 'overdue_tolerance_days']);
            }

            $account->forceFill(['overdue_tolerance_days' => $days])->save();

            $this->audit->record('credit_account.overdue_tolerance_changed', $account, [
                'from' => $previous,
                'to' => $days,
            ], $justification->text);

            return $account;
        });
    }
}
