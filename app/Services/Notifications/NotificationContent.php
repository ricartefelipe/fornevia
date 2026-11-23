<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use InvalidArgumentException;

/**
 * Conteúdo de uma notificação: texto curto e o caminho interno da tela que
 * resolve o assunto (nunca URL externa).
 */
final readonly class NotificationContent
{
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public string $path,
        public string $actionLabel = 'Abrir',
    ) {
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('O caminho da notificação deve ser interno.');
        }
    }
}
