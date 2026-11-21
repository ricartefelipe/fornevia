<?php

declare(strict_types=1);

use App\Livewire\Staff\Credit\CreditAccountShow;
use App\Livewire\Staff\Credit\CreditAccountsIndex;
use Illuminate\Support\Facades\Route;

/*
| Crédito comercial (equipe do distribuidor). Já dentro de auth, verified,
| context:staff e two-factor (routes/web.php). As telas exigem credit.view;
| alterações são autorizadas pelas Actions (credit.manage, perfil
| privilegiado com segundo fator), inclusive nas ações Livewire, que também
| reaplicam os middlewares desta rota.
*/

Route::middleware('permission:credit.view')->prefix('credito')->name('credit.')->group(function (): void {
    Route::livewire('/', CreditAccountsIndex::class)->name('index');
    Route::livewire('/{buyer}', CreditAccountShow::class)->name('show');
});
