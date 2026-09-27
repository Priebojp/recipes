<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Opens the current monthly AI grants, closes abandoned checkouts and retries failed Stripe events (idempotent).
Schedule::command('app:billing-reconcile')->dailyAt('03:15')->withoutOverlapping();

// Working photos of meal analyses after their TTL and unfinished proposals after the draft TTL (v2.1 stage 11).
Schedule::command('app:meal-analysis-cleanup')->dailyAt('03:40')->withoutOverlapping();
