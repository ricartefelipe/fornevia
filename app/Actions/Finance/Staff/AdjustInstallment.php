<?php

declare(strict_types=1);

namespace App\Actions\Finance\Staff;

use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Domain\Finance\Exceptions\PaymentNotAllowed;
use App\Enums\Finance\InstallmentStatus;
use App\Enums\Identity\Permission;
use App\Models\Finance\ReceivableInstallment;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\FinanceLocks;
use App\Services\Finance\Receivables;
use App\Support\Auth\Access;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Crédito concedido e ajuste da parcela (RF-100, RN-100), sempre por
 * lançamento compensatório: o crédito reduz o devido (por exemplo, valor
 * de devolução); o ajuste soma ou subtrai (juros, multa, correção), sem
 * deixar o devido abaixo do já pago e creditado.
 */
final readonly class AdjustInstallment
{
    public function __construct(
        private Access $access,
        private FinanceLocks $locks,
        private Receivables $receivables,
        private AuditRecorder $audit,
    ) {}

    public function credit(mixed $installmentId, string $amount, string $reason): ReceivableInstallment
    {
        $value = RecordPayment::money($amount);

        return $this->apply($installmentId, $reason, function (ReceivableInstallment $installment, string $text) use ($value): void {
            if ($value->greaterThan(Receivables::open($installment))) {
                throw new InvalidPayment('O crédito passa do saldo em aberto da parcela.', ['field' => 'amount']);
            }

            $this->receivables->credit($installment, $value, $text);
            $this->audit->record('receivable.credited', $installment, ['amount_cents' => $value->cents, 'buyer_company_id' => $installment->buyer_company_id], $text);
        });
    }

    /**
     * @param  string  $amount  valor com sinal ("-10,00" reduz)
     */
    public function adjust(mixed $installmentId, string $amount, string $reason): ReceivableInstallment
    {
        $negative = str_starts_with(trim($amount), '-');
        $value = RecordPayment::money(ltrim(trim($amount), '-+'));
        $delta = $negative ? $value->negated() : $value;

        return $this->apply($installmentId, $reason, function (ReceivableInstallment $installment, string $text) use ($delta): void {
            if ($delta->isNegative() && $delta->negated()->greaterThan(Receivables::open($installment))) {
                throw new InvalidPayment('O ajuste deixaria o devido abaixo do já pago e creditado.', ['field' => 'amount']);
            }

            $this->receivables->adjust($installment, $delta, $text);
            $this->audit->record('receivable.adjusted', $installment, ['delta_cents' => $delta->cents, 'buyer_company_id' => $installment->buyer_company_id], $text);
        });
    }

    /**
     * @param  Closure(ReceivableInstallment, string): void  $change
     */
    private function apply(mixed $installmentId, string $reason, Closure $change): ReceivableInstallment
    {
        $this->access->authorize(Permission::FinanceManage);
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidPayment('Informe o motivo (de 5 a 500 caracteres).', ['field' => 'reason']);
        }

        return DB::transaction(function () use ($installmentId, $reason, $change): ReceivableInstallment {
            [, , $installment] = $this->locks->installment($installmentId);

            if ($installment->status === InstallmentStatus::Paid) {
                throw new PaymentNotAllowed('A parcela já está quitada.');
            }

            $change($installment, $reason);

            return $installment;
        });
    }
}
