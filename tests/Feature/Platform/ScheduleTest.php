<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
| Tarefas agendadas com várias réplicas e pods substituídos a cada versão:
| uma instância por vez em todo o cluster, e a trava de sobreposição expira
| logo se o processo for encerrado no meio da execução (o padrão do Laravel
| é de 24 horas, o que suspenderia a tarefa por um dia).
*/

it('agenda as tarefas em uma única instância, com trava de sobreposição de curta duração', function (): void {
    $events = collect(app(Schedule::class)->events());

    expect($events)->not->toBeEmpty();

    $events->each(function (Event $event): void {
        expect($event->onOneServer)->toBeTrue($event->command ?? '')
            ->and($event->withoutOverlapping)->toBeTrue($event->command ?? '')
            ->and($event->expiresAt)->toBeLessThanOrEqual(30, $event->command ?? '');
    });
});
