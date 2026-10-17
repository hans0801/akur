<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dijalankan oleh service `scheduler` (compose.yaml: php artisan schedule:work).
Schedule::command('akur:prune-files')->dailyAt('03:00')->timezone('Asia/Jakarta');
Schedule::command('akur:reconcile-dues')->dailyAt('03:15')->timezone('Asia/Jakarta');
