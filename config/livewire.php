<?php

declare(strict_types=1);

/*
| Apenas as opções alteradas em relação ao padrão do Livewire.
*/
return [
    'class_namespace' => 'App\\Livewire',
    'class_path' => app_path('Livewire'),
    'view_path' => resource_path('views/livewire'),
    'component_layout' => 'layouts.app',

    // Build CSP-safe (sem unsafe-eval), compatível com a CSP com nonce.
    'csp_safe' => true,

    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#21776e',
    ],

    'temporary_file_upload' => [
        'disk' => 'private',
        'rules' => ['required', 'file', 'max:10240'],
        'directory' => 'livewire-tmp',
        'middleware' => 'throttle:30,1',
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

    'payload' => [
        'max_size' => 512 * 1024,
        'max_nesting_depth' => 10,
        'max_calls' => 30,
        'max_components' => 100,
    ],
];
