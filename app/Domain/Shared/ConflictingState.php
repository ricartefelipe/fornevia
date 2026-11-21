<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Violação causada pelo estado atual do recurso (concorrência, versão
 * desatualizada, reutilização de chave). Representada como HTTP 409.
 */
abstract class ConflictingState extends BusinessRuleViolation {}
