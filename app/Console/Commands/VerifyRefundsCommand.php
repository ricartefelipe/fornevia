<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Finance\System\VerifyRefunds;
use Illuminate\Console\Command;

/**
 * Confere no gateway os reembolsos em verificação (agendado a cada 15 minutos).
 */
final class VerifyRefundsCommand extends Command
{
    protected $signature = 'payments:verify-refunds';

    protected $description = 'Confere no gateway os reembolsos em verificação e conclui os efetivados ou recusados';

    public function handle(VerifyRefunds $verify): int
    {
        $result = $verify->handle();
        $this->info("Conferidos: {$result['checked']}; efetivados: {$result['done']}; desfeitos: {$result['failed']}; aguardando: {$result['waiting']}.");

        return self::SUCCESS;
    }
}
