<?php

declare(strict_types=1);

use App\Support\Observability\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;

it('remove valores sensíveis em qualquer nível do contexto do log', function (): void {
    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'pagamento',
        context: [
            'company_id' => 'mantido',
            'password' => 'segredo',
            'payment' => ['card_number' => '4111111111111111', 'cvv' => '123', 'amount_cents' => 1000],
            'headers' => ['Authorization' => 'Bearer abc', 'X-Request-Id' => 'req-1'],
            'asaas_api_key' => 'chave',
        ],
    );

    $context = (new RedactSensitiveData)($record)->context;

    expect($context['company_id'])->toBe('mantido')
        ->and($context['password'])->toBe('[redacted]')
        ->and($context['payment'])->toBe(['card_number' => '[redacted]', 'cvv' => '[redacted]', 'amount_cents' => 1000])
        ->and($context['headers'])->toBe(['Authorization' => '[redacted]', 'X-Request-Id' => 'req-1'])
        ->and($context['asaas_api_key'])->toBe('[redacted]');
});
