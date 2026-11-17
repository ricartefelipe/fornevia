<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sinaliza ao middleware de contexto que a transação da requisição deve
 * ser desfeita, preservando a resposta já renderizada (o pipeline do
 * Laravel converte exceções em respostas antes de retornar ao middleware).
 */
final class RollbackWithResponse extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Transação da requisição desfeita.');
    }
}
