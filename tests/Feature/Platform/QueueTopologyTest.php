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

it('permite dividir os workers de produção entre réplicas do Horizon', function (): void {
    $production = fn (): array => (require config_path('horizon.php'))['environments']['production'];

    expect($production()['operacao']['maxProcesses'])->toBe(10)
        ->and($production()['integracoes']['maxProcesses'])->toBe(4);

    $_ENV['HORIZON_OPERACAO_MAX_PROCESSES'] = $_SERVER['HORIZON_OPERACAO_MAX_PROCESSES'] = '5';
    $_ENV['HORIZON_INTEGRACOES_MAX_PROCESSES'] = $_SERVER['HORIZON_INTEGRACOES_MAX_PROCESSES'] = '2';

    try {
        expect($production()['operacao']['maxProcesses'])->toBe(5)
            ->and($production()['integracoes']['maxProcesses'])->toBe(2);
    } finally {
        unset($_ENV['HORIZON_OPERACAO_MAX_PROCESSES'], $_SERVER['HORIZON_OPERACAO_MAX_PROCESSES'],
            $_ENV['HORIZON_INTEGRACOES_MAX_PROCESSES'], $_SERVER['HORIZON_INTEGRACOES_MAX_PROCESSES']);
    }
});
