<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Finance\System\ReconcilePayments;
use Illuminate\Console\Command;

/**
 * Confere no gateway os pagamentos pendentes há mais que o prazo
 * (agendado a cada 15 minutos).
 */
final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Confere no gateway os pagamentos pendentes e aplica os pagos ou encerrados';

    public function handle(ReconcilePayments $reconcile): int
    {
        $result = $reconcile->handle();
        $this->info("Conferidos: {$result['checked']}; aplicados: {$result['settled']}; cobranças criadas: {$result['created']}; com falha: {$result['failed']}.");

        return self::SUCCESS;
    }
}
