<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Notifications\NotificationBell;
use App\Livewire\Notifications\NotificationsPage;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\Builders\TenantScenario;

/*
| Central de notificações (ADR-026) na equipe e no portal: contagem no
| cabeçalho, lista, abrir levando à tela do assunto, marcar todas e
| preferência de e-mail.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = TenantScenario::create();
    $this->staff = $this->tenant->addStaff(SystemRole::SalesRep);
    $this->buyer = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany());
    $this->notify = fn (Membership $m, string $title, string $url, int $minutesAgo = 0): string => $this->tenant->system(static function () use ($m, $title, $url, $minutesAgo): string {
        $id = (string) Str::uuid7();
        DB::table('user_notifications')->insert([
            'id' => $id, 'tenant_id' => $m->tenant_id, 'membership_id' => $m->id, 'user_id' => $m->user_id, 'outbox_message_id' => (string) Str::uuid7(),
            'kind' => 'teste', 'title' => $title, 'body' => 'Detalhe do aviso', 'url' => $url, 'created_at' => now()->subMinutes($minutesAgo),
        ]);

        return $id;
    });
    $this->page = function (string $url, Membership $membership): TestResponse {
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))->withSession(TenantScenario::sessionFor($membership))->get($url);
    };
    $this->as = function (Membership $membership, Closure $callback): mixed {
        $user = User::query()->findOrFail($membership->user_id);

        return $this->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };
});

it('mostra a contagem no cabeçalho e a central da equipe', function (): void {
    ($this->notify)($this->staff, 'Nova solicitação de cotação COT-0000001', '/cotacoes', 5);
    ($this->notify)($this->staff, 'Proposta aceita: cotação COT-0000002', '/cotacoes');

    ($this->page)(route('dashboard'), $this->staff)->assertOk()->assertSee(route('notifications.index'))->assertSeeText('2');
    ($this->page)(route('notifications.index'), $this->staff)
        ->assertOk()
        ->assertSeeInOrder(['Proposta aceita: cotação COT-0000002', 'Nova solicitação de cotação COT-0000001'])
        ->assertSeeText('Marcar todas como lidas');
    ($this->as)($this->staff, fn () => Livewire::test(NotificationBell::class)->assertSee('Notificações')->assertSee('2'));
});

it('abre a notificação levando à tela do assunto e marca como lida', function (): void {
    $id = ($this->notify)($this->buyer, 'Proposta recebida: cotação COT-0000001', '/portal/cotacoes');

    ($this->as)($this->buyer, fn () => Livewire::test(NotificationsPage::class)
        ->assertSee('Proposta recebida')
        ->call('open', $id)
        ->assertRedirect('/portal/cotacoes'));

    expect($this->tenant->system(fn (): mixed => DB::table('user_notifications')->where('id', $id)->value('read_at')))->not->toBeNull();

    ($this->as)($this->buyer, fn () => Livewire::test(NotificationsPage::class)
        ->set('onlyUnread', true)
        ->assertSee('Nenhuma notificação não lida.')
        ->call('open', 'inexistente')
        ->assertSee('Notificação não encontrada.'));
});

it('marca todas como lidas e liga ou desliga o e-mail no portal', function (): void {
    ($this->notify)($this->buyer, 'Pedido PED-0000001 confirmado', '/portal/pedidos');
    ($this->notify)($this->buyer, 'Pedido PED-0000002 confirmado', '/portal/pedidos');

    ($this->page)(route('portal.notifications'), $this->buyer)->assertOk()->assertSeeText('Pedido PED-0000001 confirmado');

    ($this->as)($this->buyer, fn () => Livewire::test(NotificationsPage::class)
        ->call('markAllRead')
        ->assertSee('2 notificações marcadas como lidas.')
        ->assertDontSee('Marcar todas como lidas')
        ->assertSee('E-mail: ligado')
        ->call('toggleEmail')
        ->assertSee('Você não receberá mais estas notificações por e-mail neste contexto.')
        ->assertSee('E-mail: desligado')
        ->call('toggleEmail')
        ->assertSee('E-mail: ligado'));
});

it('não mostra notificações de outra pessoa nem do outro contexto', function (): void {
    ($this->notify)($this->staff, 'Aviso da equipe', '/cotacoes');
    $other = $this->tenant->addStaff(SystemRole::SalesRep);

    ($this->page)(route('notifications.index'), $other)->assertOk()->assertDontSeeText('Aviso da equipe')->assertSeeText('Nenhuma notificação ainda.');
    ($this->page)(route('portal.notifications'), $this->buyer)->assertOk()->assertDontSeeText('Aviso da equipe');
});
