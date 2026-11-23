<?php

declare(strict_types=1);

namespace Tests\Support\Outbox;

use App\Support\Outbox\OutboxConsumer;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Consumidor de teste: grava um comentário de auditoria por mensagem
 * consumida (efeito observável e transacional) e falha nas primeiras
 * execuções configuradas.
 */
final class RecordingConsumer implements OutboxConsumer
{
    public static int $failuresLeft = 0;

    /** @var list<string> */
    public static array $handled = [];

    public static string $queue = 'default';

    public function name(): string
    {
        return 'teste.registro';
    }

    public function queue(): string
    {
        return self::$queue;
    }

    public function types(): array
    {
        return ['teste.evento'];
    }

    public function handle(OutboxMessage $message): void
    {
        // Efeito dentro da transação do consumo: some se o consumo falhar.
        DB::table('outbox_consumptions')->where('outbox_message_id', $message->id)->sole();

        if (self::$failuresLeft > 0) {
            self::$failuresLeft--;

            throw new RuntimeException('falha simulada no consumidor');
        }

        self::$handled[] = $message->id;
    }

    public static function reset(): void
    {
        self::$failuresLeft = 0;
        self::$handled = [];
        self::$queue = 'default';
    }
}
