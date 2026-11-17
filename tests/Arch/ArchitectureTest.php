<?php

declare(strict_types=1);

arch('código próprio declara strict_types')
    ->expect('App')
    ->toUseStrictTypes();

arch('regras de domínio não dependem do framework nem de infraestrutura')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate', 'Laravel', 'Livewire', 'App\Models', 'App\Http', 'App\Support\Tenancy'])
    ->ignoring('Illuminate\Support\Str');

arch('sem funções de depuração esquecidas')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'phpinfo'])
    ->not->toBeUsed();

arch('env() somente nos arquivos de configuração')
    ->expect('env')
    ->not->toBeUsedIn('App');

arch('controllers não acessam o banco diretamente')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB']);

arch('exceções de tenancy são específicas')
    ->expect('App\Support\Tenancy\Exceptions')
    ->toExtend('App\Support\Tenancy\Exceptions\TenancyException')
    ->ignoring('App\Support\Tenancy\Exceptions\TenancyException');
