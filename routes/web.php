<?php

use App\Http\Controllers\BillingPortalController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\PaymentController;

Route::livewire('/', 'pages::home.index')->name('home');
Route::livewire('recept/{recipe}', 'pages::home.recipe')->name('public.recipe');
Route::livewire('cennik', 'pages::billing.pricing')->name('pricing');

// Cashier routes registered here (Cashier::ignoreRoutes) so the webhook goes through the inbox controller.
Route::prefix(config('cashier.path'))->name('cashier.')->group(function () {
    Route::get('payment/{id}', [PaymentController::class, 'show'])->name('payment');
    Route::post('webhook', [StripeWebhookController::class, 'handleWebhook'])->name('webhook');
});

// Images of published recipes are public; the controller checks the household for everything else.
Route::get('media/{media}/{conversion?}', MediaController::class)->name('media.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', 'cook')->name('dashboard');

    Route::livewire('cook', 'pages::cook.index')->name('cook.index');
    Route::livewire('cook/select', 'pages::cook.select')->name('cook.select');
    Route::livewire('cook/session/{session}', 'pages::cook.session')->name('cook.session');

    Route::livewire('recipes', 'pages::recipes.index')->name('recipes.index');
    Route::livewire('recipes/create', 'pages::recipes.create')->name('recipes.create');
    Route::livewire('recipes/{recipe}', 'pages::recipes.show')->name('recipes.show');
    Route::livewire('recipes/{recipe}/edit', 'pages::recipes.edit')->name('recipes.edit');

    Route::livewire('plan', 'pages::plan.index')->name('plan.index');
    Route::livewire('plan/history', 'pages::plan.history')->name('plan.history');

    Route::livewire('family', 'pages::family.index')->name('family.index');
    Route::livewire('settings/household', 'pages::settings.household')->name('household.edit');

    Route::get('export', ExportController::class)->name('export');

    // Purchases: the owner picks an offer code, Stripe hosts the payment, webhooks grant the result.
    Route::post('checkout/plan', [CheckoutController::class, 'subscribe'])->name('checkout.plan');
    Route::post('checkout/addon', [CheckoutController::class, 'addon'])->name('checkout.addon');
    Route::livewire('checkout/success', 'pages::billing.checkout-success')->name('checkout.success');
    Route::get('checkout/cancel/{order}', [CheckoutController::class, 'cancel'])->name('checkout.cancel');
    Route::get('billing/portal', BillingPortalController::class)->name('billing.portal');
    Route::get('invite/{token}', [InvitationController::class, 'show'])->name('invite.show');
    Route::post('invite/{token}', [InvitationController::class, 'accept'])->name('invite.accept');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
