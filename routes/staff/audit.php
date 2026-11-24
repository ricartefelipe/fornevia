<?php

declare(strict_types=1);

use App\Livewire\Staff\Audit\AuditIndex;
use Illuminate\Support\Facades\Route;

/*
| Auditoria (equipe do distribuidor). Já dentro de auth, verified,
| context:staff e two-factor (routes/web.php). Somente leitura.
*/

Route::livewire('/auditoria', AuditIndex::class)->middleware('permission:audit.view')->name('audit.index');
