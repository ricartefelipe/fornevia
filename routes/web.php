<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ContextController;
use App\Http\Controllers\Web\InvitationController;
use App\Livewire\Onboarding\CreateOrganizationForm;
use App\Livewire\Platform\OrganizationsIndex;
use App\Livewire\Platform\PlansIndex;
use App\Livewire\Portal\PortalHome;
use App\Livewire\Staff\BranchesIndex;
use App\Livewire\Staff\Dashboard;
use App\Livewire\Staff\TeamMembers;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => auth()->check()
    ? redirect()->route('contexts.index')
    : redirect()->route('login'))->name('home');

// Convite: o tenant da URL só é usado após validação do token e do e-mail.
Route::get('/convites/{organization}/{token}', [InvitationController::class, 'show'])
    ->middleware('throttle:invitations')
    ->name('invitations.show');
Route::post('/convites/{organization}/{token}', [InvitationController::class, 'accept'])
    ->middleware(['auth', 'throttle:invitations'])
    ->name('invitations.accept');

// Segurança da conta: exige confirmação recente de senha (códigos de
// recuperação e segredo TOTP) e fica acessível mesmo com e-mail pendente de
// verificação, para permitir corrigir um endereço digitado errado.
Route::view('/perfil/seguranca', 'profile.security')
    ->middleware(['auth', 'password.confirm', 'cache.headers:no_store;private'])
    ->name('profile.security');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/contextos', [ContextController::class, 'index'])->name('contexts.index');
    Route::post('/contextos', [ContextController::class, 'store'])->middleware('throttle:30,1')->name('contexts.store');
    Route::post('/contextos/sair', [ContextController::class, 'leave'])->name('contexts.leave');

    Route::livewire('/onboarding', CreateOrganizationForm::class)->name('onboarding');

    Route::middleware(['context:staff', 'two-factor'])->group(function (): void {
        Route::livewire('/painel', Dashboard::class)->name('dashboard');
        Route::livewire('/equipe', TeamMembers::class)->middleware('permission:members.manage')->name('team.index');
        Route::livewire('/filiais', BranchesIndex::class)->middleware('permission:organization.manage')->name('branches.index');
    });

    Route::middleware(['context:buyer'])->prefix('portal')->name('portal.')->group(function (): void {
        Route::livewire('/', PortalHome::class)->name('home');
    });

    Route::middleware(['platform-admin', 'two-factor'])->prefix('plataforma')->name('platform.')->group(function (): void {
        Route::livewire('/planos', PlansIndex::class)->name('plans');
        Route::livewire('/organizacoes', OrganizationsIndex::class)->name('organizations');
    });
});
