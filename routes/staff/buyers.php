<?php

declare(strict_types=1);

use App\Livewire\Staff\Buyers\BuyerShow;
use App\Livewire\Staff\Buyers\BuyersIndex;
use App\Livewire\Staff\Buyers\CustomerGroupsIndex;
use Illuminate\Support\Facades\Route;

/*
| Empresas compradoras (equipe do distribuidor). Incluído pelo grupo de
| rotas da equipe em routes/web.php (auth, verified, context:staff,
| two-factor). Escrita exige buyers.manage, verificada pelas Actions.
*/

Route::livewire('/empresas', BuyersIndex::class)->middleware('permission:buyers.view')->name('buyers.index');
Route::livewire('/empresas/{buyer}', BuyerShow::class)->middleware('permission:buyers.view')->name('buyers.show');
Route::livewire('/grupos-de-clientes', CustomerGroupsIndex::class)->middleware('permission:buyers.view')->name('buyers.groups');
