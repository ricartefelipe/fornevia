<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Quotes\System\ExpireDueQuotes;
use Illuminate\Console\Command;

/**
 * Registra o vencimento das propostas e libera reservas de estoque com
 * prazo vencido (agendado a cada minuto).
 */
final class ExpireQuotes extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Registra propostas vencidas e libera reservas de estoque com prazo vencido';

    public function handle(ExpireDueQuotes $expire): int
    {
        $result = $expire->handle();

        $this->info("Versões vencidas: {$result['expired']}; reservas liberadas: {$result['holds_released']}; cotações ocupadas: {$result['skipped']}.");

        return self::SUCCESS;
    }
}
