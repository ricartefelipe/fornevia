<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Actions\Credit\Exceptions\CreditExceptionNotActive;
use App\Domain\Buyers\Justification;
use App\Enums\Identity\Permission;
use App\Models\Credit\CreditAccount;
use App\Models\Credit\CreditException;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Revoga uma exceção de limite vigente ou agendada, com justificativa
 * registrada na auditoria. A exceção deixa de contar imediatamente.
 *
 * Bloqueia a conta antes da exceção (ADR-006): a revogação espera uma
 * confirmação de pedido a prazo em andamento, e uma confirmação posterior
 * já decide sem o valor revogado.
 */
final readonly class RevokeCreditException
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private CreditAccounts $accounts,
        private Clock $clock,
    ) {}

    public function handle(CreditException $exception, string $reason): CreditException
    {
        $this->access->authorize(Permission::CreditManage);

        $justification = Justification::fromString($reason);

        return DB::transaction(function () use ($exception, $justification): CreditException {
            $accountId = CreditException::query()->whereKey($exception->id)->firstOrFail()->credit_account_id;
            CreditAccount::query()->lockForUpdate()->findOrFail($accountId);
            $locked = CreditException::query()->lockForUpdate()->findOrFail($exception->id);
            $now = $this->clock->now();

            if ($locked->revoked_at !== null) {
                throw new CreditExceptionNotActive('Esta exceção já foi revogada.');
            }

            if ($locked->valid_until->lessThanOrEqualTo($now)) {
                throw new CreditExceptionNotActive('A vigência desta exceção já terminou; não há o que revogar.');
            }

            $locked->forceFill([
                'revoked_at' => $now,
                'revoked_by_user_id' => $this->accounts->actorUserId(),
            ])->save();

            $this->audit->record('credit_exception.revoked', $locked, [
                'credit_account_id' => $locked->credit_account_id,
                'amount_cents' => $locked->amount_cents->cents,
                'valid_from' => $locked->valid_from->toIso8601String(),
                'valid_until' => $locked->valid_until->toIso8601String(),
            ], $justification->text);

            return $locked;
        });
    }
}
