<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Todas as tarefas rodam em uma única instância (onOneServer) e sem
| sobreposição. A trava de sobreposição expira em poucos minutos (o padrão
| é de 24 horas): se o processo do agendador for encerrado no meio de uma
| tarefa, por exemplo na troca de pod de uma atualização, a tarefa volta a
| rodar no ciclo seguinte ao prazo, em vez de ficar suspensa por um dia.
*/

// Vencimento das propostas e das reservas de estoque das cotações (ADR-025).
Schedule::command('quotes:expire')->everyMinute()->withoutOverlapping(10)->onOneServer();

// Conciliação dos pagamentos pendentes no gateway (ADR-028).
Schedule::command('payments:reconcile')->everyFifteenMinutes()->withoutOverlapping(30)->onOneServer();

// Conferência dos reembolsos sem resposta do gateway (ADR-028).
Schedule::command('payments:verify-refunds')->everyFifteenMinutes()->withoutOverlapping(30)->onOneServer();

// Novas tentativas dos webhooks enviados (ADR-029).
Schedule::command('webhooks:retry')->everyMinute()->withoutOverlapping(10)->onOneServer();
