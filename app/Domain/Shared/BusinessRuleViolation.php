<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DomainException;

/**
 * Violação de regra de negócio, com código estável para clientes da API
 * e detalhes seguros para exibição (sem dados internos).
 */
abstract class BusinessRuleViolation extends DomainException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    abstract public function errorCode(): string;

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
