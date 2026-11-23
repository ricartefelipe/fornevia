<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Staff;

use App\Domain\Fulfillment\Exceptions\InvalidFreightRule;
use App\Domain\Shared\Money;
use App\Enums\Identity\Permission;
use App\Models\Fulfillment\FreightRule;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cria ou altera uma regra de frete do distribuidor (RF-092): nome, UFs
 * atendidas (vazio = todas), valor por pedido, isenção opcional a partir de
 * um subtotal, prioridade e situação.
 */
final readonly class SaveFreightRule
{
    public const array STATES = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function __construct(private Access $access, private AuditRecorder $audit) {}

    public function handle(?string $ruleId, string $name, string $states, string $amount, ?string $freeFrom, int $priority, bool $active): FreightRule
    {
        $this->access->authorize(Permission::PricingManage);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidFreightRule('Informe um nome de até 80 caracteres.', ['field' => 'name']);
        }

        $ufs = self::states($states);
        $value = self::money($amount, 'amount');
        $threshold = $freeFrom !== null && trim($freeFrom) !== '' ? self::money($freeFrom, 'free_from') : null;

        if ($value->isNegative()) {
            throw new InvalidFreightRule('O valor do frete não pode ser negativo.', ['field' => 'amount']);
        }

        if ($threshold !== null && ! $threshold->isPositive()) {
            throw new InvalidFreightRule('A isenção vale a partir de um subtotal positivo.', ['field' => 'free_from']);
        }

        if ($priority < 0 || $priority > 1000) {
            throw new InvalidFreightRule('A prioridade vai de 0 a 1.000.', ['field' => 'priority']);
        }

        return DB::transaction(function () use ($ruleId, $name, $ufs, $value, $threshold, $priority, $active): FreightRule {
            $rule = $ruleId === null ? new FreightRule : FreightRule::query()->lockForUpdate()->findOrFail(UntrustedId::uuid($ruleId, FreightRule::class));
            $creating = ! $rule->exists;
            $rule->forceFill([
                'name' => $name, 'states' => $ufs, 'amount_cents' => $value, 'free_from_cents' => $threshold, 'priority' => $priority, 'is_active' => $active,
            ])->save();
            $this->audit->record($creating ? 'freight_rule.created' : 'freight_rule.updated', $rule, [
                'states' => $ufs, 'amount_cents' => $value->cents, 'free_from_cents' => $threshold?->cents, 'priority' => $priority, 'is_active' => $active,
            ]);

            return $rule;
        });
    }

    /**
     * @return list<string>
     */
    private static function states(string $value): array
    {
        $parts = preg_split('/[\s,;\/]+/', mb_strtoupper(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $invalid = array_diff($parts, self::STATES);

        if ($invalid !== []) {
            throw new InvalidFreightRule('UF inválida: '.implode(', ', $invalid).'.', ['field' => 'states']);
        }

        $unique = array_values(array_unique($parts));
        sort($unique);

        return $unique;
    }

    private static function money(string $value, string $field): Money
    {
        try {
            return Money::parse(str_replace(['R$', ' '], '', $value));
        } catch (InvalidArgumentException) {
            throw new InvalidFreightRule('Valor inválido. Use o formato 1.234,56.', ['field' => $field]);
        }
    }
}
