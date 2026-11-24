<?php

declare(strict_types=1);

use App\Actions\Notifications\MyNotifications;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| Notificações pessoais (ADR-026): cada pessoa vê só as próprias, só marca
| a leitura e não grava preferência em nome de outra associação; a criação
| é do contexto de sistema.
*/

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->company = $this->tenant->addBuyerCompany();
    $this->ana = $this->tenant->addBuyerUser($this->company);
    $this->bia = $this->tenant->addBuyerUser($this->company);
    $this->staff = $this->tenant->addStaff(SystemRole::SalesRep);
    $this->notify = fn (Membership $m, string $title = 'Aviso'): string => $this->tenant->system(static function () use ($m, $title): string {
        $id = (string) Str::uuid7();
        DB::table('user_notifications')->insert([
            'id' => $id, 'tenant_id' => $m->tenant_id, 'membership_id' => $m->id, 'user_id' => $m->user_id, 'outbox_message_id' => (string) Str::uuid7(),
            'kind' => 'teste', 'title' => $title, 'body' => 'Corpo', 'url' => '/portal', 'created_at' => now(),
        ]);

        return $id;
    });
});

it('mostra a cada pessoa só as próprias notificações', function (): void {
    $mine = ($this->notify)($this->ana);
    $theirs = ($this->notify)($this->bia);
    ($this->notify)($this->staff);

    expect($this->tenant->actingAs($this->ana, static fn (): array => DB::table('user_notifications')->pluck('id')->all()))->toBe([$mine])
        ->and($this->tenant->actingAs($this->staff, static fn (): int => DB::table('user_notifications')->count()))->toBe(1)
        ->and($this->tenant->actingAs($this->ana, static fn (): int => app(MyNotifications::class)->unreadCount()))->toBe(1)
        ->and(fn () => $this->tenant->actingAs($this->ana, static fn () => app(MyNotifications::class)->open($theirs)))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('só deixa marcar a leitura e só o sistema cria ou apaga', function (): void {
    $mine = ($this->notify)($this->ana);

    expect($this->tenant->actingAs($this->ana, static fn (): string => app(MyNotifications::class)->open($mine)))->toBe('/portal')
        ->and(fn () => $this->tenant->actingAs($this->ana, static fn () => DB::table('user_notifications')->where('id', $mine)->update(['title' => 'Alterado'])))->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => $this->tenant->actingAs($this->ana, fn () => DB::table('user_notifications')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->ana->tenant_id, 'membership_id' => $this->ana->id, 'user_id' => $this->ana->user_id,
            'kind' => 'teste', 'title' => 'x', 'body' => 'x', 'url' => '/portal', 'created_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security')
        ->and($this->tenant->actingAs($this->ana, static fn (): int => DB::table('user_notifications')->where('id', $mine)->delete()))->toBe(0);
});

it('recusa notificação com link externo ou em nome de outra pessoa', function (): void {
    $insert = fn (array $values) => $this->tenant->system(fn () => DB::table('user_notifications')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->ana->tenant_id, 'membership_id' => $this->ana->id, 'user_id' => $this->ana->user_id,
        'kind' => 'teste', 'title' => 'x', 'body' => 'x', 'url' => '/portal', 'created_at' => now(), ...$values,
    ]));

    expect(fn () => $insert(['url' => '//exemplo.com']))->toThrow(QueryException::class, 'user_notifications_url_check')
        ->and(fn () => $insert(['url' => 'https://exemplo.com']))->toThrow(QueryException::class, 'user_notifications_url_check')
        ->and(fn () => $insert(['user_id' => $this->bia->user_id]))->toThrow(QueryException::class, 'não corresponde à associação');
});

it('grava a preferência de e-mail só da própria associação', function (): void {
    $this->tenant->actingAs($this->ana, static fn () => app(MyNotifications::class)->setEmailEnabled(false));

    expect($this->tenant->actingAs($this->ana, static fn (): bool => app(MyNotifications::class)->emailEnabled()))->toBeFalse()
        ->and($this->tenant->actingAs($this->bia, static fn (): bool => app(MyNotifications::class)->emailEnabled()))->toBeTrue();

    $this->tenant->actingAs($this->ana, static fn () => app(MyNotifications::class)->setEmailEnabled(true));
    expect($this->tenant->actingAs($this->ana, static fn (): bool => app(MyNotifications::class)->emailEnabled()))->toBeTrue();

    // Em nome de outra associação: a pessoa da linha precisa ser a da associação.
    expect(fn () => $this->tenant->actingAs($this->ana, fn () => DB::table('notification_settings')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->bia->tenant_id, 'membership_id' => $this->bia->id, 'user_id' => $this->ana->user_id,
        'email_enabled' => false, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'não corresponde à associação')
        ->and(fn () => $this->tenant->actingAs($this->ana, fn () => DB::table('notification_settings')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->bia->tenant_id, 'membership_id' => $this->bia->id, 'user_id' => $this->bia->user_id,
            'email_enabled' => false, 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security');
});
