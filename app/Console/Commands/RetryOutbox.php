<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Outbox\OutboxRelay;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Devolve à publicação mensagens da outbox sem novas tentativas ("dead"),
 * depois de corrigida a causa.
 */
final class RetryOutbox extends Command
{
    protected $signature = 'outbox:retry {message? : Id da mensagem} {--all : Todas as mensagens sem novas tentativas}';

    protected $description = 'Reenvia mensagens da outbox que esgotaram as tentativas';

    public function handle(OutboxRelay $relay): int
    {
        $id = $this->argument('message');

        if (! $this->option('all') && ! is_string($id)) {
            $this->error('Informe o id da mensagem ou --all.');

            return self::INVALID;
        }

        if (is_string($id) && ! Str::isUuid($id)) {
            $this->error('Id de mensagem inválido.');

            return self::INVALID;
        }

        $count = $relay->retry($this->option('all') ? null : $id);
        $this->info("Mensagens devolvidas à publicação: {$count}.");

        return self::SUCCESS;
    }
}
