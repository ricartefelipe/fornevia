<?php

declare(strict_types=1);

use App\Actions\Fulfillment\Staff\ShipShipment;
use App\Models\Identity\Membership;
use App\Support\Auth\Access;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Tests\Support\Concurrency\WorkerGate;

/*
 * Cenários de expedição: o worker age como a requisição da equipe da
 * expedição e pode segurar a transação aberta antes do commit ("hold").
 */
return [

    'ship-shipment' => static function (array $args, WorkerGate $gate): array {
        $membership = app(TenantDatabase::class)->run(
            ExecutionContext::staff($args['user_id'], $args['tenant_id'], $args['membership_id']),
            static fn () => Membership::query()->with(['role', 'tenant'])->findOrFail($args['membership_id']),
        );

        return app(TenantDatabase::class)->run($membership->executionContext(), static function () use ($args, $gate, $membership): array {
            app(Access::class)->bindMembership($membership);

            $shipment = app(ShipShipment::class)->handle((string) $args['shipment_id'], 'Rápida Cargas', $args['tracking'] ?? null);

            $gate->pass($args['hold'] ?? null);

            return ['status' => $shipment->status->value];
        });
    },

];
