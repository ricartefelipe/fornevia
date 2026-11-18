<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Actions\Credit\Exceptions\CreditAccountUnchanged;
use App\Domain\Buyers\Justification;
use App\Enums\Credit\CreditAccountStatus;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Credit\CreditAccount;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Desbloqueia o crédito comercial com justificativa. A conta volta a
 * aceitar pedidos a prazo dentro do limite disponível; o motivo do
 * bloqueio anterior é preservado na auditoria.
 */
final readonly class UnblockCreditAccount
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private CreditAccounts $accounts,
    ) {}

    public function handle(BuyerCompany $company, string $reason): CreditAccount
    {
        $this->access->authorize(Permission::CreditManage);

        $justification = Justification::fromString($reason);

        return DB::transaction(function () use ($company, $justification): CreditAccount {
            $account = $this->accounts->lock($company);
            $previous = $account->status;

            if ($previous === CreditAccountStatus::Active) {
                throw new CreditAccountUnchanged('O crédito desta empresa não está bloqueado.', ['field' => 'status']);
            }

            $blockedReason = $account->blocked_reason;
            $account->forceFill([
                'status' => CreditAccountStatus::Active,
                'blocked_reason' => null,
            ])->save();

            $this->accounts->recordLimitChange($account, $account->limit_cents, $previous, $justification);
            $this->audit->record('credit_account.unblocked', $account, [
                'from' => $previous->value,
                'to' => CreditAccountStatus::Active->value,
                'blocked_reason' => $blockedReason,
            ], $justification->text);

            return $account;
        });
    }
}
