<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Prepara o banco de testes com o papel de manutenção e limpa os dados
 * entre testes com TRUNCATE (sem transação envolvendo o teste).
 *
 * - Migrations rodam uma vez por processo com a conexão pgsql_owner
 *   (FORNEVIA_TEST_FRESH=1 força migrate:fresh).
 * - A aplicação usa a conexão padrão (papel fornevia_app, sujeito a RLS).
 */
trait RefreshFornevia
{
    private static bool $forneviaMigrated = false;

    /** @var list<string>|null */
    private static ?array $forneviaTables = null;

    protected function setUpRefreshFornevia(): void
    {
        $database = (string) config('database.connections.pgsql.database');

        if (! str_ends_with($database, '_test') && ! str_ends_with($database, '_browser')) {
            throw new RuntimeException("Banco de testes inválido: {$database}. Use um banco com sufixo _test.");
        }

        if (! self::$forneviaMigrated) {
            $command = getenv('FORNEVIA_TEST_FRESH') === '1' ? 'migrate:fresh' : 'migrate';
            $this->artisan($command, ['--database' => 'pgsql_owner', '--force' => true])->assertSuccessful();
            self::$forneviaMigrated = true;
            self::$forneviaTables = null;
        }

        $this->truncateForneviaTables();

        $this->beforeApplicationDestroyed(function (): void {
            $connection = DB::connection();

            if ($connection->transactionLevel() > 0) {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }

                throw new RuntimeException('Teste encerrado com transação aberta na conexão da aplicação.');
            }

            // Fecha explicitamente as conexões do teste: sem isso, elas só são
            // liberadas na coleta de lixo e podem esgotar max_connections.
            foreach (array_keys(DB::getConnections()) as $name) {
                DB::disconnect($name);
            }
        });
    }

    private function truncateForneviaTables(): void
    {
        $owner = DB::connection('pgsql_owner');

        self::$forneviaTables ??= array_map(
            static fn (object $row): string => '"'.$row->tablename.'"',
            $owner->select("select tablename from pg_tables where schemaname = 'public' and tablename <> 'migrations' order by tablename"),
        );

        if (self::$forneviaTables !== []) {
            $owner->statement('truncate table '.implode(', ', self::$forneviaTables).' restart identity cascade');
        }
    }
}
