<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\SyncAccountJob;
use App\Models\Account;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Cada minuto verifica qué cuentas deben sincronizarse según auto_sync_interval.
| Usa cache para recordar la última ejecución por cuenta y respetar el intervalo.
|
*/

Schedule::call(function () {
    $accounts = Account::where('is_active', true)
        ->where('is_deleted', false)
        ->where('auto_sync_interval', '>', 0)
        ->get();

    foreach ($accounts as $account) {
        $cacheKey        = "last_sync_account_{$account->id}";
        $lastSync        = cache($cacheKey);
        $intervalMinutes = (int) $account->auto_sync_interval;

        if (!$lastSync || now()->diffInMinutes($lastSync) >= $intervalMinutes) {
            SyncAccountJob::dispatch($account->id);
            cache([$cacheKey => now()], now()->addHours(24));
        }
    }
})->everyMinute()->name('auto-sync-accounts')->withoutOverlapping();
