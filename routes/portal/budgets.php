<?php

declare(strict_types=1);

use App\Livewire\Portal\Budgets\BudgetShow;
use App\Livewire\Portal\Budgets\BudgetsIndex;
use Illuminate\Support\Facades\Route;

// Orçamentos por centro de custo (RN-055). Já dentro de context:buyer, com
// prefixo /portal e nomes portal.* (routes/web.php). As telas e a Action
// de gravação (SaveCostCenterBudget) exigem portal.budgets de novo.
Route::livewire('/orcamentos', BudgetsIndex::class)
    ->middleware('permission:portal.budgets')
    ->name('budgets');

Route::livewire('/orcamentos/{budget}', BudgetShow::class)
    ->middleware('permission:portal.budgets')
    ->name('budgets.show');
