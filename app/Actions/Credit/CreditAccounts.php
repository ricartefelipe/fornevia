<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Actions\Credit\Exceptions\CreditAccountNotOpen;
use App\Actions\Credit\Exceptions\InvalidCreditValue;
use App\Domain\Buyers\Justification;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Money;
use App\Enums\Credit\CreditAccountStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Credit\CreditAccount;
use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Apoio comum às Actions de gestão do crédito comercial: carregamento no
 * escopo do tenant, bloqueio da conta (FOR UPDATE, passo 4 da ADR-006, o
 * mesmo lock usado pelo comprometimento de crédito na confirmação de
 * pedidos), validações de valores e registro do histórico de limite.
 */
final readonly class CreditAccounts
{
    /** Tolerância máxima de vencidos (mesmo intervalo da constraint da tabela). */
    public const int MAX_TOLERANCE_DAYS = 365;

    public function __construct(
        private CurrentContext $context,
        private Clock $clock,
    ) {}

    /**
     * Recarrega a empresa no tenant ativo: empresa de outro tenant equivale
     * a inexistente.
     */
    public function company(BuyerCompany $company): BuyerCompany
    {
        return BuyerCompany::query()->findOrFail($company->id);
    }

    /**
     * Conta da empresa com bloqueio de linha. Serializa limite, situação,
     * tolerância e exceções com o comprometimento de crédito do checkout.
     */
    public function lock(BuyerCompany $company): CreditAccount
    {
        $company = $this->company($company);

        return CreditAccount::query()
            ->where('buyer_company_id', $company->id)
            ->lockForUpdate()
            ->first()
            ?? throw new CreditAccountNotOpen("A empresa {$company->trade_name} não tem conta de crédito comercial aberta.");
    }

    /**
     * Usuário responsável pela alteração (histórico e exceções exigem pessoa
     * identificada; integrações sem usuário não gerenciam crédito).
     */
    public function actorUserId(): string
    {
        return $this->context->require()->userId
            ?? throw new AuthorizationException('A gestão de crédito exige um usuário identificado.');
    }

    public function assertLimit(Money $limit): void
    {
        if ($limit->isNegative()) {
            throw new InvalidCreditValue('O limite de crédito não pode ser negativo.', ['field' => 'limit']);
        }
    }

    public function tolerance(int $days): int
    {
        if ($days < 0 || $days > self::MAX_TOLERANCE_DAYS) {
            throw new InvalidCreditValue(
                'A tolerância de vencidos deve ficar entre 0 e '.self::MAX_TOLERANCE_DAYS.' dias.',
                ['field' => 'overdue_tolerance_days'],
            );
        }

        return $days;
    }

    /**
     * Soma das exceções não revogadas e ainda não encerradas (vigentes ou
     * agendadas), em centavos.
     */
    public function openExceptionsCents(CreditAccount $account): int
    {
        return (int) DB::table('credit_exceptions')
            ->where('credit_account_id', $account->id)
            ->whereNull('revoked_at')
            ->where('valid_until', '>', $this->clock->now())
            ->sum('amount_cents');
    }

    /**
     * Limite somado às exceções em aberto precisa caber no intervalo de
     * Money: a posição de crédito (inclusive no checkout) soma esses valores.
     */
    public function assertWithinMagnitude(Money $limit, int $openExceptionsCents, Money $additional, string $field): void
    {
        if ($limit->cents + $openExceptionsCents + $additional->cents > Money::MAX_CENTS) {
            throw new AmountOutOfRange(
                'O limite somado às exceções em aberto excede o valor máximo suportado.',
                ['field' => $field],
            );
        }
    }

    /**
     * Histórico append-only de limite e situação (credit_limit_changes).
     */
    public function recordLimitChange(
        CreditAccount $account,
        Money $previousLimit,
        CreditAccountStatus $previousStatus,
        Justification $justification,
    ): void {
        DB::table('credit_limit_changes')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $account->tenant_id,
            'credit_account_id' => $account->id,
            'previous_limit_cents' => $previousLimit->cents,
            'new_limit_cents' => $account->limit_cents->cents,
            'previous_status' => $previousStatus->value,
            'new_status' => $account->status->value,
            'reason' => $justification->text,
            'changed_by_user_id' => $this->actorUserId(),
            // Instante da gravação (após o lock da conta), e não o início da
            // transação: a ordem do histórico segue a ordem das alterações.
            'created_at' => DB::raw('clock_timestamp()'),
        ]);
    }
}
