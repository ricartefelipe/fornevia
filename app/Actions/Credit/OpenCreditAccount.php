<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Domain\Buyers\Justification;
use App\Domain\Credit\Exceptions\CreditAccountAlreadyOpen;
use App\Domain\Shared\Money;
use App\Enums\Credit\CreditAccountStatus;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Credit\CreditAccount;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Abre a conta de crédito comercial da empresa compradora com o limite
 * inicial concedido e a tolerância de vencidos. Comprometido e recebível
 * nascem zerados e só mudam por movimentos (ADR-005).
 *
 * A concessão inicial entra no histórico de limite (de R$ 0,00 para o
 * limite concedido) com a justificativa. Uma conta por empresa: aberturas
 * simultâneas são resolvidas pela unicidade no banco.
 */
final readonly class OpenCreditAccount
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private CreditAccounts $accounts,
    ) {}

    public function handle(BuyerCompany $company, Money $limit, int $overdueToleranceDays, string $reason): CreditAccount
    {
        $this->access->authorize(Permission::CreditManage);

        $justification = Justification::fromString($reason);
        $this->accounts->assertLimit($limit);
        $tolerance = $this->accounts->tolerance($overdueToleranceDays);

        try {
            return DB::transaction(function () use ($company, $limit, $tolerance, $justification): CreditAccount {
                $company = $this->accounts->company($company);
                $this->accounts->actorUserId();

                if (CreditAccount::query()->where('buyer_company_id', $company->id)->exists()) {
                    throw self::alreadyOpen($company);
                }

                $account = new CreditAccount;
                $account->forceFill([
                    'buyer_company_id' => $company->id,
                    'limit_cents' => $limit,
                    'status' => CreditAccountStatus::Active,
                    'blocked_reason' => null,
                    'overdue_tolerance_days' => $tolerance,
                ])->save();

                $account = CreditAccount::query()->findOrFail($account->id);

                $this->accounts->recordLimitChange($account, Money::zero(), CreditAccountStatus::Active, $justification);
                $this->audit->record('credit_account.opened', $account, [
                    'limit_cents' => $limit->cents,
                    'overdue_tolerance_days' => $tolerance,
                ], $justification->text);

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::alreadyOpen($this->accounts->company($company));
        }
    }

    private static function alreadyOpen(BuyerCompany $company): CreditAccountAlreadyOpen
    {
        return new CreditAccountAlreadyOpen("A empresa {$company->trade_name} já tem conta de crédito comercial.");
    }
}
