<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\ApiResources;
use App\Actions\Api\ApiWrites;
use App\Support\Idempotency\IdempotentResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 (RF-110; contrato em docs/openapi.json). Controller fino: cada
 * ação delega para ApiResources, que autoriza pela permissão do cliente de
 * API e valida filtros e paginação.
 */
final class ApiController
{
    public function __construct(private readonly ApiResources $resources, private readonly ApiWrites $writes) {}

    public function orders(Request $request): JsonResponse
    {
        return new JsonResponse($this->resources->orders($request->query()));
    }

    public function order(string $order): JsonResponse
    {
        return new JsonResponse(['data' => $this->resources->order($order)]);
    }

    public function buyers(Request $request): JsonResponse
    {
        return new JsonResponse($this->resources->buyers($request->query()));
    }

    public function skus(Request $request): JsonResponse
    {
        return new JsonResponse($this->resources->skus($request->query()));
    }

    public function stock(Request $request): JsonResponse
    {
        return new JsonResponse($this->resources->stock($request->query()));
    }

    public function invoices(Request $request): JsonResponse
    {
        return new JsonResponse($this->resources->invoices($request->query()));
    }

    public function shipmentEvent(Request $request, string $shipment): JsonResponse
    {
        return new JsonResponse(['data' => $this->resources->shipmentEvent($shipment, $request->all())], 201);
    }

    public function createBuyer(Request $request): JsonResponse
    {
        return self::written($this->writes->createBuyer($request->header('Idempotency-Key'), $request->all()), 201);
    }

    public function receiveStock(Request $request): JsonResponse
    {
        return self::written($this->writes->receiveStock($request->header('Idempotency-Key'), $request->all()), 201);
    }

    public function adjustStock(Request $request): JsonResponse
    {
        return self::written($this->writes->adjustStock($request->header('Idempotency-Key'), $request->all()), 201);
    }

    public function cancelOrder(Request $request, string $order): JsonResponse
    {
        return self::written($this->writes->cancelOrder($request->header('Idempotency-Key'), $order, $request->all()), 200);
    }

    /** Repetição com a mesma chave devolve a resposta original, marcada como repetida. */
    private static function written(IdempotentResult $result, int $status): JsonResponse
    {
        return new JsonResponse(['data' => $result->response], $status, ['Idempotent-Replayed' => $result->replayed ? 'true' : 'false']);
    }
}
