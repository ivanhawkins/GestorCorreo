<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
])->group(function () {

    Route::get('/login', fn() => view('login'))->name('login');

    // Todo lo demás → dashboard (el JS comprueba el token)
    Route::get('/{any?}', fn() => view('dashboard'))->where('any', '.*');

});
