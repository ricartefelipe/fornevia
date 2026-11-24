<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\ApiResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 (RF-110; contrato em docs/openapi.yaml). Controller fino: cada
 * ação delega para ApiResources, que autoriza pela permissão do cliente de
 * API e valida filtros e paginação.
 */
final class ApiController
{
    public function __construct(private readonly ApiResources $resources) {}

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
}
