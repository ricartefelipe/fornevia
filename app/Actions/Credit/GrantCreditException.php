<?php

declare(strict_types=1);

namespace App\Actions\Credit;

use App\Data\Credit\CreditExceptionPeriod;
use App\Domain\Buyers\Justification;
use App\Domain\Credit\Exceptions\InvalidCreditValue;
use App\Domain\Shared\Money;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Credit\CreditException;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Concede exceção temporária de limite: valor somado ao limite durante a
 * vigência (do primeiro ao último dia, no fuso do negócio), com
 * justificativa e o usuário atual como responsável. Exceções vigentes se
 * somam; fora da vigência ou revogadas não contam (RN-080).
 *
 * A vigência começa hoje ou depois e dura no máximo MAX_DAYS dias: prazos
 * maiores são alteração de limite. A conta é bloqueada para serializar a
 * concessão com o comprometimento de crédito do checkout.
 */
final readonly class GrantCreditException
{
    public const int MAX_DAYS = 180;

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private CreditAccounts $accounts,
        private Clock $clock,
    ) {}

    public function handle(BuyerCompany $company, Money $amount, CarbonImmutable $firstDay, CarbonImmutable $lastDay, string $reason): CreditException
    {
        $this->access->authorize(Permission::CreditManage);

        $justification = Justification::fromString($reason);

        if (! $amount->isPositive()) {
            throw new InvalidCreditValue('O valor da exceção deve ser maior que zero.', ['field' => 'amount']);
        }

        $period = $this->period($firstDay, $lastDay);

        return DB::transaction(function () use ($company, $amount, $period, $justification): CreditException {
            $account = $this->accounts->lock($company);
            $this->accounts->assertWithinMagnitude($account->limit_cents, $this->accounts->openExceptionsCents($account), $amount, 'amount');

            $exception = new CreditException;
            $exception->forceFill([
                'credit_account_id' => $account->id,
                'buyer_company_id' => $account->buyer_company_id,
                'amount_cents' => $amount,
                'valid_from' => $period->validFrom(),
                'valid_until' => $period->validUntil(),
                'reason' => $justification->text,
                'granted_by_user_id' => $this->accounts->actorUserId(),
            ])->save();

            $this->audit->record('credit_exception.granted', $exception, [
                'credit_account_id' => $account->id,
                'amount_cents' => $amount->cents,
                'first_day' => $period->firstDay,
                'last_day' => $period->lastDay,
            ], $justification->text);

            return $exception;
        });
    }

    private function period(CarbonImmutable $firstDay, CarbonImmutable $lastDay): CreditExceptionPeriod
    {
        $period = CreditExceptionPeriod::fromDays($firstDay->toDateString(), $lastDay->toDateString());

        if ($period->firstDay < $this->clock->today()->toDateString()) {
            throw new InvalidCreditValue('A vigência da exceção não pode começar antes de hoje.', ['field' => 'first_day']);
        }

        if ($period->lastDay < $period->firstDay) {
            throw new InvalidCreditValue('O último dia da vigência não pode ser anterior ao primeiro.', ['field' => 'last_day']);
        }

        if ($period->days() > self::MAX_DAYS) {
            throw new InvalidCreditValue(
                'Exceções temporárias valem por no máximo '.self::MAX_DAYS.' dias; para prazos maiores, altere o limite.',
                ['field' => 'last_day'],
            );
        }

        return $period;
    }
}
