<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\Exceptions\InvalidMinimumMargin;
use App\Enums\Identity\Permission;
use App\Models\Pricing\PricingPolicy;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Support\Facades\DB;

/**
 * Define a margem mínima padrão do tenant (pontos-base, em pricing_policies,
 * interna), usada na avaliação comercial de descontos quando o SKU não
 * define a sua.
 *
 * A margem mínima não participa da resolução do preço de venda; por isso a
 * versão de preços não é incrementada aqui.
 */
final readonly class UpdatePricingSettings
{
    public const int MIN_BPS = -10_000;

    public const int MAX_BPS = 10_000;

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private CurrentContext $context,
    ) {}

    public function handle(int $minMarginBps): PricingPolicy
    {
        $this->access->authorize(Permission::PricingManage);

        if ($minMarginBps < self::MIN_BPS || $minMarginBps > self::MAX_BPS) {
            throw new InvalidMinimumMargin('A margem mínima deve estar entre -100% e 100%.', ['field' => 'min_margin_bps']);
        }

        return DB::transaction(function () use ($minMarginBps): PricingPolicy {
            $tenantId = $this->context->tenantId();

            DB::statement(
                'insert into pricing_policies (tenant_id, updated_at) values (?, now()) on conflict (tenant_id) do nothing',
                [$tenantId],
            );

            $settings = PricingPolicy::query()->lockForUpdate()->findOrFail($tenantId);
            $before = $settings->min_margin_bps;

            if ($before === $minMarginBps) {
                return $settings;
            }

            $settings->forceFill(['min_margin_bps' => $minMarginBps])->save();

            $this->audit->record('pricing_settings.updated', null, [
                'before' => ['min_margin_bps' => $before],
                'after' => ['min_margin_bps' => $minMarginBps],
            ]);

            return $settings;
        });
    }
}
