<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
| Guardas de schema: valem para todas as tabelas, inclusive as criadas no
| futuro. Uma tabela nova com tenant_id sem RLS, ou uma FK entre tabelas
| de tenant que não inclua tenant_id, falha aqui.
*/

it('habilita e força RLS com política de tenant em toda tabela que possui tenant_id', function (): void {
    $tables = DB::connection('pgsql_owner')->select(<<<'SQL'
        select c.relname as table_name, c.relrowsecurity as enabled, c.relforcerowsecurity as forced,
               exists (select 1 from pg_policy p where p.polrelid = c.oid and p.polname = 'tenant_isolation') as has_policy
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace and n.nspname = 'public'
        join pg_attribute a on a.attrelid = c.oid and a.attname = 'tenant_id' and not a.attisdropped
        where c.relkind = 'r'
        order by c.relname
        SQL);

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        expect([$table->table_name, $table->enabled, $table->forced, $table->has_policy])
            ->toBe([$table->table_name, true, true, true]);
    }
});

it('usa chaves estrangeiras compostas com tenant_id entre tabelas do mesmo tenant', function (): void {
    $violations = DB::connection('pgsql_owner')->select(<<<'SQL'
        select con.conname, src.relname as source, dst.relname as target
        from pg_constraint con
        join pg_class src on src.oid = con.conrelid
        join pg_class dst on dst.oid = con.confrelid
        where con.contype = 'f'
          and exists (select 1 from pg_attribute where attrelid = src.oid and attname = 'tenant_id' and not attisdropped)
          and exists (select 1 from pg_attribute where attrelid = dst.oid and attname = 'tenant_id' and not attisdropped)
          and not exists (
              select 1 from pg_attribute a
              where a.attrelid = src.oid and a.attname = 'tenant_id' and a.attnum = any (con.conkey)
          )
        SQL);

    expect($violations)->toBe([]);
});

it('não concede ao papel de aplicação propriedade, superuser ou BYPASSRLS', function (): void {
    $app = config('database.roles.app');
    $role = DB::connection('pgsql_owner')->selectOne(
        'select rolsuper, rolbypassrls, rolcreaterole, rolcreatedb from pg_roles where rolname = ?',
        [$app],
    );

    expect((array) $role)->toBe([
        'rolsuper' => false,
        'rolbypassrls' => false,
        'rolcreaterole' => false,
        'rolcreatedb' => false,
    ]);
});
