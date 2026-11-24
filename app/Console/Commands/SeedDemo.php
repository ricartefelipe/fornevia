<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\DemoData;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Cria os dados de demonstração (dois distribuidores). Recusado em produção.
 */
final class SeedDemo extends Command
{
    protected $signature = 'demo:seed {--password= : Senha de todos os usuários de demonstração (padrão: DEMO_PASSWORD)}';

    protected $description = 'Cria dois distribuidores de demonstração com equipe, catálogo, compradores e pedidos';

    public function handle(DemoData $demo): int
    {
        $password = (string) ($this->option('password') ?: config('app.demo_password'));

        if (mb_strlen($password) < 12) {
            $this->error('Informe uma senha de demonstração com ao menos 12 caracteres (--password ou DEMO_PASSWORD).');

            return self::FAILURE;
        }

        try {
            $summary = $demo->seed($password);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($summary as $tenant) {
            $this->info($tenant['tenant']);

            foreach ($tenant['emails'] as $email) {
                $this->line('  '.$email);
            }
        }

        $this->line('Perfis com permissões administrativas pedem a configuração da verificação em duas etapas no primeiro acesso.');

        return self::SUCCESS;
    }
}
