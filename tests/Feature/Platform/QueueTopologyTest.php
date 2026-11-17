<?php

declare(strict_types=1);

use App\Notifications\Identity\MemberInvitation;
use Carbon\CarbonImmutable;

/*
| Toda fila usada pela aplicação precisa ser consumida por algum supervisor
| do Horizon; caso contrário, os jobs ficam parados no Redis.
*/

it('consome em algum supervisor do Horizon as filas usadas pelas notificações', function (): void {
    $notification = new MemberInvitation('Distribuidora Exemplo', 'Ana', 'Comprador', 'http://localhost/convite', CarbonImmutable::now());

    $consumed = collect(config('horizon.defaults'))->pluck('queue')->flatten()->unique()->all();

    expect($consumed)->toContain($notification->queue);
});

it('mantém o timeout dos supervisores abaixo do retry_after da conexão redis', function (): void {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    foreach (config('horizon.defaults') as $name => $supervisor) {
        expect($supervisor['timeout'])->toBeLessThan($retryAfter, "Supervisor {$name}");
    }
});

it('protege o painel do Horizon mesmo no ambiente local', function (): void {
    $this->get('/horizon')->assertRedirect(route('login'));
});
