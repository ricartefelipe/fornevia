<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency\Scenarios;

use App\Actions\Quotes\Portal\AcceptQuoteVersion;
use App\Actions\Quotes\Staff\SubmitQuoteVersion;
use App\Data\Quotes\AcceptQuoteData;
use App\Data\Quotes\QuoteLineInput;
use App\Data\Quotes\QuoteVersionData;
use App\Data\Quotes\ReviewedQuoteState;
use App\Data\Quotes\ReviewedQuoteVersion;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;
use App\Models\Identity\Membership;
use App\Support\Auth\Access;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Tests\Support\Concurrency\WorkerGate;

/**
 * Cenários de cotação: o worker age como a requisição do comprador (aceite)
 * ou da equipe (nova versão) e pode segurar a transação aberta antes do
 * commit ("hold").
 */
final class QuoteScenarios
{
    /**
     * @return array<string, Closure(array<string, mixed>, WorkerGate): array<string, mixed>>
     */
    public static function all(): array
    {
        $bind = static function (array $args, bool $staff): Membership {
            $context = $staff
                ? ExecutionContext::staff($args['user_id'], $args['tenant_id'], $args['membership_id'])
                : ExecutionContext::buyer($args['user_id'], $args['tenant_id'], $args['buyer_company_id'], $args['membership_id']);

            return app(TenantDatabase::class)->run($context, static fn () => Membership::query()->with(['role', 'tenant', 'buyerCompany'])->findOrFail($args['membership_id']));
        };

        return [

            'accept-quote' => static function (array $args, WorkerGate $gate) use ($bind): array {
                $membership = $bind($args, false);

                return app(TenantDatabase::class)->run($membership->executionContext(), static function () use ($args, $gate, $membership): array {
                    app(Access::class)->bindMembership($membership);

                    $order = app(AcceptQuoteVersion::class)->handle($args['quote_id'], new AcceptQuoteData(
                        reviewed: new ReviewedQuoteVersion($args['version_id'], $args['fingerprint'], (bool) $args['hold_active']),
                        idempotencyKey: (string) $args['idempotency_key'],
                        shippingAddressId: (string) $args['shipping_address_id'],
                    ));

                    $gate->pass($args['hold'] ?? null);

                    return ['order_id' => $order->id];
                });
            },

            'submit-quote-version' => static function (array $args, WorkerGate $gate) use ($bind): array {
                $membership = $bind($args, true);

                return app(TenantDatabase::class)->run($membership->executionContext(), static function () use ($args, $gate, $membership): array {
                    app(Access::class)->bindMembership($membership);

                    $version = app(SubmitQuoteVersion::class)->handle(new QuoteVersionData(
                        quoteId: (string) $args['quote_id'],
                        reviewed: new ReviewedQuoteState($args['with_buyer_version_id'] ?? null, $args['preparing_version_id'] ?? null),
                        idempotencyKey: (string) $args['idempotency_key'],
                        lines: [new QuoteLineInput((string) $args['sku_id'], Quantity::of((string) $args['quantity']), Percentage::parse((string) ($args['discount'] ?? '0')))],
                        paymentTermId: (string) $args['payment_term_id'],
                        validUntilDay: (string) $args['valid_day'],
                        stockHoldUntilDay: $args['hold_day'] ?? null,
                    ));

                    $gate->pass($args['hold'] ?? null);

                    return ['version_id' => $version->id, 'status' => $version->status->value];
                });
            },

        ];
    }
}
