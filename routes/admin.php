<?php

use Illuminate\Support\Facades\Route;

/*
 * Platform administration for the application owner. Requires the platform-admin role and (by default) confirmed
 * two-factor authentication; settings changes additionally re-confirm the password.
 */
Route::middleware(['auth', 'verified', 'platform-admin', 'throttle:60,1'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::livewire('/', 'pages::admin.index')->name('index');
        Route::livewire('ai', 'pages::admin.ai')->name('ai');
        Route::livewire('households', 'pages::admin.households')->name('households');
        Route::livewire('audit', 'pages::admin.audit')->name('audit');

        Route::middleware('password.confirm')->group(function () {
            Route::livewire('ai/settings', 'pages::admin.ai-settings')->name('ai.settings');
            Route::livewire('ai/rates', 'pages::admin.ai-rates')->name('ai.rates');
        });
    });
