<?php

declare(strict_types=1);

namespace App\Support\Outbox;

/**
 * Consumidor de mensagens da outbox (ADR-026). Roda no contexto de sistema
 * do tenant da mensagem, em transação própria, e só uma vez por mensagem
 * (outbox_consumptions): o efeito precisa estar todo nessa transação.
 */
interface OutboxConsumer
{
    /** Nome estável, gravado em outbox_consumptions (até 80 caracteres). */
    public function name(): string;

    /** Fila do job que processa as mensagens deste consumidor. */
    public function queue(): string;

    /** @return list<string> tipos de mensagem tratados */
    public function types(): array;

    public function handle(OutboxMessage $message): void;
}
