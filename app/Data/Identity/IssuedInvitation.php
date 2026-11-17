<?php

declare(strict_types=1);

namespace App\Data\Identity;

use App\Models\Identity\Invitation;
use SensitiveParameter;

/**
 * Resultado da emissão: o token em claro existe apenas aqui, para envio
 * por e-mail; o banco guarda somente o hash.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public Invitation $invitation,
        #[SensitiveParameter] public string $plainToken,
    ) {}
}
