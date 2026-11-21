<?php

declare(strict_types=1);

use App\Livewire\Portal\Approvals\Admin\ApprovalDelegationsIndex;
use App\Livewire\Portal\Approvals\Admin\ApprovalRuleForm;
use App\Livewire\Portal\Approvals\Admin\ApprovalRulesIndex;
use Illuminate\Support\Facades\Route;

// Regras de aprovação e delegações da empresa compradora (RF-050, RN-053).
// Já dentro de context:buyer, com prefixo /portal e nomes portal.*
// (routes/web.php). As Actions conferem as permissões de novo.
Route::middleware('permission:portal.approval_rules')->group(static function (): void {
    Route::livewire('/aprovacoes/regras', ApprovalRulesIndex::class)
        ->name('approval-rules');

    Route::livewire('/aprovacoes/regras/nova', ApprovalRuleForm::class)
        ->name('approval-rules.create');

    Route::livewire('/aprovacoes/regras/{rule}', ApprovalRuleForm::class)
        ->whereUuid('rule')
        ->name('approval-rules.edit');
});

// Delegações: o administrador (portal.approval_rules) administra as de toda a
// empresa; o aprovador (portal.approvals) delega as próprias aprovações. Como
// o middleware de permissão exige todas as permissões listadas, o acesso por
// uma ou outra é conferido no componente e nas Actions.
Route::livewire('/aprovacoes/delegacoes', ApprovalDelegationsIndex::class)
    ->name('delegations');
