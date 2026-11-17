<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

/**
 * Registro associado a um tenant diferente do contexto ativo.
 */
final class CrossTenantReference extends TenancyException {}
