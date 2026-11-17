<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

/**
 * Operação executada sem o contexto de tenant exigido.
 */
final class TenantContextMissing extends TenancyException {}
