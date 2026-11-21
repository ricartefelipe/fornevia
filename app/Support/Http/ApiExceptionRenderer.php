<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\ConflictingState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Formato único de erro da API:
 * { "error": { "code", "message", "details"?, "fields"? }, "request_id" }
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $message, $extra] = match (true) {
            $e instanceof ValidationException => [422, 'validation_failed', 'Os dados enviados são inválidos.', ['fields' => $e->errors()]],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Autenticação necessária.', []],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, 'forbidden', 'Acesso não autorizado a este recurso.', []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'not_found', 'Recurso não encontrado.', []],
            $e instanceof ThrottleRequestsException => [429, 'too_many_requests', 'Limite de requisições excedido.', []],
            $e instanceof ConflictingState => [409, $e->errorCode(), $e->getMessage(), $this->details($e)],
            $e instanceof BusinessRuleViolation => [422, $e->errorCode(), $e->getMessage(), $this->details($e)],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'http_'.$e->getStatusCode(), $this->httpMessage($e), []],
            default => [500, 'internal_error', 'Erro interno. Utilize o request_id ao contatar o suporte.', []],
        };

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse([
            'error' => ['code' => $code, 'message' => $message] + $extra,
            'request_id' => Context::get('correlation_id'),
        ], $status, $headers);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function details(BusinessRuleViolation $e): array
    {
        $details = $e->details();

        return $details === [] ? [] : ['details' => $details];
    }

    private function httpMessage(HttpExceptionInterface $e): string
    {
        return match ($e->getStatusCode()) {
            400 => 'Requisição inválida.',
            405 => 'Método não permitido.',
            419 => 'Sessão expirada.',
            503 => 'Serviço indisponível.',
            default => 'Erro na requisição.',
        };
    }
}
