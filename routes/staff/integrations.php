<?php

declare(strict_types=1);

use App\Livewire\Staff\Integrations\ApiClientsPage;
use App\Livewire\Staff\Integrations\CsvImportsPage;
use App\Livewire\Staff\Integrations\WebhooksPage;
use Illuminate\Support\Facades\Route;

/*
| Integrações (RF-110 a RF-113). Já dentro de auth, verified, context:staff
| e two-factor (routes/web.php). Tudo exige integrations.manage; a
| importação confere também a permissão do tipo importado.
*/

Route::middleware('permission:integrations.manage')->prefix('integracoes')->name('integrations.')->group(function (): void {
    Route::livewire('/', ApiClientsPage::class)->name('index');
    Route::livewire('/webhooks', WebhooksPage::class)->name('webhooks');
    Route::livewire('/importacoes', CsvImportsPage::class)->name('imports');
});
