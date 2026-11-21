<?php

declare(strict_types=1);

namespace App\Services\Credit;

use App\Domain\Credit\CreditDecision;
use App\Domain\Credit\CreditPosition;
use App\Domain\Credit\Exceptions\CreditNotApproved;
use App\Domain\Shared\Money;
use App\Enums\Credit\CreditAccountStatus;
use App\Enums\Credit\CreditMovementType;
use App\Models\Credit\CreditAccount;
use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Crédito comercial (RN-080 a RN-083). A decisão é tomada com a conta
 * bloqueada (FOR UPDATE): duas compras concorrentes nunca consomem o mesmo
 * limite. Valores exibidos em tela ou em cache não substituem esta checagem.
 *
 * Exige transação aberta em contexto de equipe ou sistema.
 */
final readonly class CreditLedger
{
    public function __construct(
        private CurrentContext $context,
        private Clock $clock,
    ) {}

    public function positionFor(string $buyerCompanyId, bool $lock = false): CreditPosition
    {
        $account = $this->account($buyerCompanyId, $lock);

        if ($account === null) {
            return new CreditPosition(Money::zero(), Money::zero(), Money::zero(), Money::zero(), false, false);
        }

        return new CreditPosition(
            $account->limit_cents,
            $this->activeExceptions($account->id),
            $account->committed_cents,
            $account->receivable_cents,
            $account->status === CreditAccountStatus::Blocked,
            $this->hasOverdueBeyondTolerance($account),
        );
    }

    /**
     * Compromete crédito para um pedido a prazo, ou recusa com o motivo.
     */
    public function commitForOrder(string $buyerCompanyId, string $orderId, Money $amount): void
    {
        $this->assertInTransaction();

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('Comprometimento de crédito exige valor positivo.');
        }

        $account = $this->account($buyerCompanyId, lock: true);
        $position = $this->positionFor($buyerCompanyId);
        $decision = $account === null ? CreditDecision::Insufficient : $position->decide($amount);

        if ($account === null || ! $decision->approved()) {
            throw new CreditNotApproved($decision->message(), [
                'decision' => $decision->value,
                'available_cents' => $position->available()->cents,
                'required_cents' => $amount->cents,
            ]);
        }

        $this->movement($account, CreditMovementType::OrderCommitment, $amount, Money::zero(), 'order', $orderId, null);
    }

    /**
     * Libera comprometimento de pedido (cancelamento de itens não faturados).
     */
    public function releaseCommitment(string $buyerCompanyId, string $orderId, Money $amount, string $reason): void
    {
        $this->assertInTransaction();

        if (! $amount->isPositive()) {
            return;
        }

        $account = $this->account($buyerCompanyId, lock: true) ?? throw new LogicException('Conta de crédito inexistente.');
        $this->movement($account, CreditMovementType::CommitmentRelease, $amount->negated(), Money::zero(), 'order', $orderId, $reason);
    }

    private function account(string $buyerCompanyId, bool $lock): ?CreditAccount
    {
        $query = CreditAccount::query()->where('buyer_company_id', $buyerCompanyId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function activeExceptions(string $accountId): Money
    {
        $now = $this->clock->now();
        $sum = DB::table('credit_exceptions')
            ->where('credit_account_id', $accountId)
            ->whereNull('revoked_at')
            ->where('valid_from', '<=', $now)
            ->where('valid_until', '>', $now)
            ->sum('amount_cents');

        return Money::ofCents((int) $sum);
    }

    /**
     * Títulos vencidos acima da tolerância. Recebíveis são introduzidos pelo
     * módulo financeiro; enquanto não houver tabela de recebíveis, não há
     * vencidos a considerar.
     */
    private function hasOverdueBeyondTolerance(CreditAccount $account): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('receivable_installments')) {
            return false;
        }

        return DB::table('receivable_installments')
            ->where('buyer_company_id', $account->buyer_company_id)
            ->where('status', 'open')
            ->where('due_date', '<', $this->clock->today()->subDays($account->overdue_tolerance_days)->toDateString())
            ->exists();
    }

    private function movement(CreditAccount $account, CreditMovementType $type, Money $committedDelta, Money $receivableDelta, string $referenceType, string $referenceId, ?string $reason): void
    {
        DB::table('credit_movements')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->context->tenantId(),
            'credit_account_id' => $account->id,
            'buyer_company_id' => $account->buyer_company_id,
            'type' => $type->value,
            'committed_delta' => $committedDelta->cents,
            'receivable_delta' => $receivableDelta->cents,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reason' => $reason,
            'actor_user_id' => $this->context->require()->userId,
            'correlation_id' => Context::get('correlation_id'),
            'created_at' => now(),
        ]);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Operações de crédito exigem transação aberta.');
        }
    }
}
