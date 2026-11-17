<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\RefreshFornevia;

/**
 * Base dos testes que usam a aplicação e o PostgreSQL real.
 *
 * O banco é limpo por TRUNCATE antes de cada teste (RefreshFornevia), e
 * não por transação envolvendo o teste: commits, rollbacks, locks e RLS
 * se comportam como em produção.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshFornevia;
}
