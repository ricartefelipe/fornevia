<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

/**
 * Tentativa de alternar o tenant dentro de uma transação aberta.
 */
final class TenantContextConflict extends TenancyException {}
