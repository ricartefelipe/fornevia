<?php

declare(strict_types=1);

use App\Livewire\Portal\Approvals\Inbox\ApprovalShow;
use App\Livewire\Portal\Approvals\Inbox\ApprovalsInbox;
use Illuminate\Support\Facades\Route;

// Fila de aprovação interna do comprador (RF-050). Já dentro de
// context:buyer, com prefixo /portal e nomes portal.* (routes/web.php). As
// consultas e as Actions conferem as permissões de novo; a reatribuição de
// aprovadores exige também portal.approval_rules.
Route::livewire('/aprovacoes', ApprovalsInbox::class)
    ->middleware('permission:portal.approvals')
    ->name('approvals');

// Só identificadores no formato UUID: outros caminhos em /aprovacoes/*
// (regras, delegações) não são capturados por esta rota.
Route::livewire('/aprovacoes/{order}', ApprovalShow::class)
    ->middleware('permission:portal.approvals')
    ->whereUuid('order')
    ->name('approvals.show');
