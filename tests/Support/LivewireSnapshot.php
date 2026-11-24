<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Snapshot de um componente Livewire renderizado numa página, para enviar
 * atualizações pelo endpoint real. Componentes do layout (como a contagem
 * de notificações no cabeçalho) não são o componente da página.
 */
final class LivewireSnapshot
{
    /** Componentes do layout, presentes em todas as páginas autenticadas. */
    public const array LAYOUT_COMPONENTS = ['notifications.notification-bell'];

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $page
     */
    public static function page(TestResponse $page): string
    {
        foreach (self::all($page) as $name => $snapshot) {
            if (! in_array($name, self::LAYOUT_COMPONENTS, true)) {
                return $snapshot;
            }
        }

        throw new RuntimeException('Snapshot do componente da página não encontrado.');
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $page
     * @return array<string, string> nome do componente => snapshot, na ordem da página
     */
    public static function all(TestResponse $page): array
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);
        $snapshots = [];

        foreach ($matches[1] as $raw) {
            $json = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
            /** @var array{memo: array{name: string}} $decoded */
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $snapshots[$decoded['memo']['name']] ??= $json;
        }

        return $snapshots;
    }
}
