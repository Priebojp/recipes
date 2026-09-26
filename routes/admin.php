<?php

use Illuminate\Support\Facades\Route;

/*
 * Platform administration for the application owner. Requires the platform-admin role and (by default) confirmed
 * two-factor authentication; pages with financial or configuration actions additionally re-confirm the password.
 */
Route::middleware(['auth', 'verified', 'platform-admin', 'throttle:60,1'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::livewire('/', 'pages::admin.index')->name('index');
        Route::livewire('ai', 'pages::admin.ai')->name('ai');
        Route::livewire('households', 'pages::admin.households')->name('households');
        Route::livewire('orders', 'pages::admin.orders')->name('orders');
        Route::livewire('refunds', 'pages::admin.refunds')->name('refunds');
        Route::livewire('usage', 'pages::admin.usage')->name('usage');
        Route::livewire('audit', 'pages::admin.audit')->name('audit');

        Route::middleware('password.confirm')->group(function () {
            Route::livewire('households/{household}', 'pages::admin.household')->name('households.show');
            Route::livewire('subscriptions', 'pages::admin.subscriptions')->name('subscriptions');
            Route::livewire('orders/{order}', 'pages::admin.order')->name('orders.show');
            Route::livewire('catalog', 'pages::admin.catalog')->name('catalog');
            Route::livewire('stripe-events', 'pages::admin.stripe-events')->name('stripe-events');
            Route::livewire('ai/settings', 'pages::admin.ai-settings')->name('ai.settings');
            Route::livewire('ai/rates', 'pages::admin.ai-rates')->name('ai.rates');
        });
    });
