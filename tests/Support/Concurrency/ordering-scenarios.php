<?php

declare(strict_types=1);

use App\Actions\Ordering\PlaceOrder;
use App\Data\Ordering\PlaceOrderData;
use App\Models\Identity\Membership;
use App\Support\Auth\Access;
use App\Support\Tenancy\TenantDatabase;
use Tests\Support\Concurrency\WorkerGate;

/*
 * Cenários de pedido: o worker age como a requisição de checkout de um
 * usuário comprador (contexto de comprador validado) e pode segurar a
 * transação aberta, com os locks adquiridos, antes do commit ("hold").
 */
return [

    'place-order' => static function (array $args, WorkerGate $gate): array {
        $membership = app(TenantDatabase::class)->run(
            App\Support\Tenancy\ExecutionContext::buyer($args['user_id'], $args['tenant_id'], $args['buyer_company_id'], $args['membership_id']),
            static fn () => Membership::query()->with(['role', 'tenant', 'buyerCompany'])->findOrFail($args['membership_id']),
        );

        return app(TenantDatabase::class)->run($membership->executionContext(), static function () use ($args, $gate, $membership): array {
            app(Access::class)->bindMembership($membership);
            $gate->pass($args['gate'] ?? null);

            $order = app(PlaceOrder::class)->handle(new PlaceOrderData(
                cartId: (string) $args['cart_id'],
                idempotencyKey: (string) $args['idempotency_key'],
                shippingAddressId: (string) $args['shipping_address_id'],
                paymentTermId: (string) $args['payment_term_id'],
            ));

            $gate->pass($args['hold'] ?? null);

            return ['order_id' => $order->id, 'number' => $order->number];
        });
    },

    // Cancelamento total como na tela do comprador (contexto de comprador).
    'cancel-order' => static function (array $args, WorkerGate $gate): array {
        $membership = app(TenantDatabase::class)->run(
            App\Support\Tenancy\ExecutionContext::buyer($args['user_id'], $args['tenant_id'], $args['buyer_company_id'], $args['membership_id']),
            static fn () => Membership::query()->with(['role', 'tenant', 'buyerCompany'])->findOrFail($args['membership_id']),
        );

        return app(TenantDatabase::class)->run($membership->executionContext(), static function () use ($args, $gate, $membership): array {
            app(Access::class)->bindMembership($membership);
            $gate->pass($args['gate'] ?? null);

            $order = app(App\Actions\Ordering\CancelOrderItems::class)->handle(
                App\Models\Ordering\Order::query()->findOrFail($args['order_id']),
                null,
                'Pedido duplicado pelo cliente',
            );

            return ['status' => $order->commercial_status->value];
        });
    },

    // Segura o lock de uma reserva até o portão ser liberado: alarga a janela
    // entre a liberação de uma linha do pedido e a da seguinte.
    'hold-reservation' => static function (array $args, WorkerGate $gate): array {
        return app(TenantDatabase::class)->run(App\Support\Tenancy\ExecutionContext::system((string) $args['tenant_id']), static function () use ($args, $gate): array {
            Illuminate\Support\Facades\DB::table('stock_reservations')->where('id', $args['reservation_id'])->lockForUpdate()->first();
            $gate->pass($args['hold'] ?? null);

            return ['held' => true];
        });
    },

];
