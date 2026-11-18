<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Data\Pricing\PaymentTermData;
use App\Domain\Pricing\Exceptions\InvalidPaymentTerm;
use App\Domain\Pricing\Exceptions\PaymentTermCodeTaken;
use App\Enums\Identity\Permission;
use App\Models\Pricing\PaymentTerm;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cria ou altera uma condição de pagamento do distribuidor.
 *
 * Regras: código único no tenant; de 1 a 12 parcelas; dias de vencimento
 * entre 0 e 365, em ordem não decrescente. À vista corresponde a uma única
 * parcela no dia 0; a prazo exige ao menos um vencimento após o
 * faturamento e consome o crédito comercial da empresa compradora.
 * Condições de pagamento não alteram o preço resolvido (sem nova versão
 * de preços).
 */
final readonly class SavePaymentTerm
{
    public const int MAX_INSTALLMENTS = 12;

    public const int MAX_DAYS = 365;

    private const string CODE_PATTERN = '/^[A-Z0-9][A-Z0-9._-]{0,19}$/D';

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(?PaymentTerm $term, PaymentTermData $data): PaymentTerm
    {
        $this->access->authorize(Permission::PricingManage);

        $code = mb_strtoupper(trim($data->code));
        $name = trim($data->name);
        $days = $data->installmentDays;

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidPaymentTerm(
                'Informe um código de até 20 caracteres: letras, números, ponto, hífen ou sublinhado.',
                ['field' => 'code'],
            );
        }

        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidPaymentTerm('Informe um nome de até 80 caracteres.', ['field' => 'name']);
        }

        self::assertInstallments($days, $data->requiresCredit);

        try {
            return DB::transaction(function () use ($term, $data, $code, $name, $days): PaymentTerm {
                $model = $term === null ? new PaymentTerm : PaymentTerm::query()->lockForUpdate()->findOrFail($term->id);

                $taken = PaymentTerm::query()
                    ->where('code', $code)
                    ->when($model->exists, static fn ($query) => $query->whereKeyNot($model->id))
                    ->exists();

                if ($taken) {
                    throw new PaymentTermCodeTaken("Já existe uma condição de pagamento com o código {$code}.", ['field' => 'code']);
                }

                $creating = ! $model->exists;
                $before = $creating ? null : self::snapshot($model);

                $model->fill(['code' => $code, 'name' => $name, 'is_active' => $data->isActive]);
                $model->forceFill(['installment_days' => $days, 'requires_credit' => $data->requiresCredit])->save();

                $this->audit->record($creating ? 'payment_term.created' : 'payment_term.updated', $model, [
                    'before' => $before,
                    'after' => self::snapshot($model),
                ]);

                return $model;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PaymentTermCodeTaken("Já existe uma condição de pagamento com o código {$code}.", ['field' => 'code']);
        }
    }

    /**
     * @param  list<int>  $days
     */
    private static function assertInstallments(array $days, bool $requiresCredit): void
    {
        $count = count($days);

        if ($count < 1 || $count > self::MAX_INSTALLMENTS) {
            throw new InvalidPaymentTerm('Informe de 1 a '.self::MAX_INSTALLMENTS.' parcelas.', ['field' => 'installment_days']);
        }

        $previous = 0;

        foreach ($days as $day) {
            if ($day < 0 || $day > self::MAX_DAYS) {
                throw new InvalidPaymentTerm('Os dias de vencimento devem estar entre 0 e '.self::MAX_DAYS.'.', ['field' => 'installment_days']);
            }

            if ($day < $previous) {
                throw new InvalidPaymentTerm('Os dias de vencimento devem estar em ordem crescente.', ['field' => 'installment_days']);
            }

            $previous = $day;
        }

        if (! $requiresCredit && $days !== [0]) {
            throw new InvalidPaymentTerm('Condição à vista tem uma única parcela no dia 0. Para vencimentos posteriores, marque como a prazo.', ['field' => 'installment_days']);
        }

        if ($requiresCredit && max($days) === 0) {
            throw new InvalidPaymentTerm('Condição a prazo precisa de ao menos um vencimento após o faturamento.', ['field' => 'installment_days']);
        }
    }

    /**
     * @return array{code: string, name: string, installment_days: list<int>, requires_credit: bool, is_active: bool}
     */
    private static function snapshot(PaymentTerm $term): array
    {
        return [
            'code' => $term->code,
            'name' => $term->name,
            'installment_days' => $term->installment_days,
            'requires_credit' => $term->requires_credit,
            'is_active' => $term->is_active,
        ];
    }
}
