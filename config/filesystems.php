<?php

declare(strict_types=1);

/*
| Todos os arquivos de negócio (anexos, imagens de produto, importações e
| exportações) ficam em disco privado. Downloads passam por controller com
| autorização e verificação de tenant; não há link público de storage.
*/
return [

    'default' => env('FILESYSTEM_DISK', 'private'),

    'disks' => [

        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => true,
        ],

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
            'report' => true,
        ],

    ],

    'links' => [],

];
