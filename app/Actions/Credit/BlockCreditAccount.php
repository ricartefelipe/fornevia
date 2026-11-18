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
 * Bloqueia o crédito comercial da empresa: novos pedidos a prazo são
 * recusados, independentemente do limite disponível. O motivo fica na
 * conta (exibido à equipe), no histórico de limite e na auditoria.
 * Pedidos já confirmados não são afetados.
 */
final readonly class BlockCreditAccount
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

            if ($previous === CreditAccountStatus::Blocked) {
                throw new CreditAccountUnchanged('O crédito desta empresa já está bloqueado.', ['field' => 'status']);
            }

            $account->forceFill([
                'status' => CreditAccountStatus::Blocked,
                'blocked_reason' => $justification->text,
            ])->save();

            $this->accounts->recordLimitChange($account, $account->limit_cents, $previous, $justification);
            $this->audit->record('credit_account.blocked', $account, [
                'from' => $previous->value,
                'to' => CreditAccountStatus::Blocked->value,
            ], $justification->text);

            return $account;
        });
    }
}
