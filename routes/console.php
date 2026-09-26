<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Opens the current monthly AI grants, closes abandoned checkouts and retries failed Stripe events (idempotent).
Schedule::command('app:billing-reconcile')->dailyAt('03:15')->withoutOverlapping();
