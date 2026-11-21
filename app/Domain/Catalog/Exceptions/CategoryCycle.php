<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * A categoria escolhida como pai é a própria categoria ou uma de suas
 * descendentes, o que criaria um ciclo na hierarquia.
 */
final class CategoryCycle extends BusinessRuleViolation
{
    public static function create(): self
    {
        return new self('A categoria não pode ficar abaixo de si mesma nem de uma de suas subcategorias.');
    }

    public function errorCode(): string
    {
        return 'category_cycle';
    }
}
