<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ProductMediaController;
use Illuminate\Support\Facades\Route;

// Download de imagens e documentos de produtos pela equipe do distribuidor.
// Já dentro de auth, verified, context:staff e two-factor (routes/web.php).
Route::get('/catalogo/midias/{media}', [ProductMediaController::class, 'download'])
    ->middleware('permission:catalog.view')
    ->name('catalog.media.download');
