<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| Catch-all route: serves the Vue SPA index.html for all non-API requests.
| The API routes are defined in routes/api.php.
*/

Route::get('/{any?}', function () {
    $indexPath = public_path('index.html');
    if (file_exists($indexPath)) {
        return response()->file($indexPath);
    }
    return response('GestorCorreo API - OK', 200);
})->where('any', '.*');
