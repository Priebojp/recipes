<?php

use App\Http\Controllers\PrivacyExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');
    Route::livewire('settings/usage', 'pages::settings.usage')->name('usage.index');
    Route::livewire('settings/subscription', 'pages::settings.subscription')->name('subscription.edit');
    Route::livewire('settings/privacy', 'pages::settings.privacy')->name('privacy.edit');
    Route::get('settings/privacy/export', PrivacyExportController::class)->name('privacy.export');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
