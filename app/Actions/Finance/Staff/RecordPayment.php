<?php

declare(strict_types=1);

namespace App\Actions\Finance\Staff;

use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Domain\Finance\Exceptions\PaymentNotAllowed;
use App\Domain\Shared\Money;
use App\Enums\Finance\InstallmentStatus;
use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\PaymentSource;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Identity\Permission;
use App\Models\Finance\Payment;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\FinanceLocks;
use App\Services\Finance\Receivables;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Outbox\Outbox;
use InvalidArgumentException;

/**
 * Pagamento recebido fora do gateway (transferência, Pix direto, dinheiro),
 * registrado pela equipe: confirmado na hora, até o saldo em aberto da
 * parcela; pagamento parcial mantém a parcela em aberto.
 */
final readonly class RecordPayment
{
    public function __construct(
        private Access $access,
        private IdempotencyGuard $idempotency,
        private FinanceLocks $locks,
        private Receivables $receivables,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $installmentId, string $amount, PaymentMethod $method, string $idempotencyKey): Payment
    {
        $this->access->authorize(Permission::FinanceManage);
        $value = self::money($amount);

        $result = $this->idempotency->run('payment.record', $idempotencyKey, ['installment' => (string) $installmentId, 'amount' => $value->cents, 'method' => $method->value], function () use ($installmentId, $value, $method): array {
            [, $invoice, $installment] = $this->locks->installment($installmentId);

            if ($installment->status !== InstallmentStatus::Open) {
                throw new PaymentNotAllowed('A parcela já está quitada.');
            }

            $open = Receivables::open($installment);

            if ($value->greaterThan($open)) {
                throw new InvalidPayment("O valor passa do saldo em aberto da parcela ({$open->format()}).", ['field' => 'amount']);
            }

            $payment = new Payment;
            $payment->forceFill([
                'buyer_company_id' => $installment->buyer_company_id,
                'installment_id' => $installment->id,
                'amount_cents' => $value,
                'method' => $method,
                'source' => PaymentSource::Manual,
                'status' => PaymentStatus::Confirmed,
                'confirmed_at' => $this->clock->now(),
                'created_by_user_id' => $this->access->userId(),
            ])->save();

            $this->receivables->pay($installment, $payment);
            $this->outbox->record('payment.confirmed', 'payment', $payment->id, ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_cents' => $value->cents]);
            $this->audit->record('payment.recorded', $payment, ['invoice_id' => $invoice->id, 'amount_cents' => $value->cents, 'buyer_company_id' => $installment->buyer_company_id]);

            return ['resource_type' => 'payment', 'resource_id' => $payment->id, 'response' => []];
        });

        return Payment::query()->findOrFail($result->resourceId);
    }

    public static function money(string $amount, string $field = 'amount'): Money
    {
        try {
            $value = Money::parse(trim(str_replace(['R$', "\u{a0}", ' '], '', $amount)));
        } catch (InvalidArgumentException) {
            throw new InvalidPayment('Valor inválido. Use o formato 1.234,56.', ['field' => $field]);
        }

        if (! $value->isPositive()) {
            throw new InvalidPayment('Informe um valor positivo.', ['field' => $field]);
        }

        return $value;
    }
}
