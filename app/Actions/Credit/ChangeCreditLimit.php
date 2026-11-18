<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Actions\Credit\Exceptions\CreditAccountUnchanged;
use App\Domain\Buyers\Justification;
use App\Domain\Shared\Money;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Credit\CreditAccount;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Altera o limite de crédito comercial com justificativa (RS-080),
 * registrada no histórico de limite e na auditoria.
 *
 * A conta é bloqueada (FOR UPDATE) antes da leitura do limite anterior:
 * uma confirmação de pedido a prazo em andamento termina antes (e a
 * redução enxerga o comprometimento dela) ou espera a alteração e decide
 * com o novo limite. A redução abaixo da exposição atual é permitida: os
 * pedidos já confirmados permanecem, e novos pedidos a prazo são recusados
 * até haver limite disponível.
 */
final readonly class ChangeCreditLimit
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private CreditAccounts $accounts,
    ) {}

    public function handle(BuyerCompany $company, Money $newLimit, string $reason): CreditAccount
    {
        $this->access->authorize(Permission::CreditManage);

        $justification = Justification::fromString($reason);
        $this->accounts->assertLimit($newLimit);

        return DB::transaction(function () use ($company, $newLimit, $justification): CreditAccount {
            $account = $this->accounts->lock($company);
            $previous = $account->limit_cents;

            if ($previous->equals($newLimit)) {
                throw new CreditAccountUnchanged("O limite já é {$previous->format()}.", ['field' => 'limit']);
            }

            $this->accounts->assertWithinMagnitude($newLimit, $this->accounts->openExceptionsCents($account), Money::zero(), 'limit');

            $account->forceFill(['limit_cents' => $newLimit])->save();

            $this->accounts->recordLimitChange($account, $previous, $account->status, $justification);
            $this->audit->record('credit_account.limit_changed', $account, [
                'from_cents' => $previous->cents,
                'to_cents' => $newLimit->cents,
            ], $justification->text);

            return $account;
        });
    }
}
