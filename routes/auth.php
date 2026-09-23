<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;

// Guest Routes
Route::middleware('guest')->group(function () {
    Route::get('giris', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('giris', [AuthenticatedSessionController::class, 'store']);

    // Dalga 18: yonetici ekledi, kullanici ilk giriste sifresini belirler
    Route::post('giris/sifre-belirle', [AuthenticatedSessionController::class, 'setPassword'])
        ->name('login.set-password');

    Route::get('sifremi-unuttum', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('sifremi-unuttum', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('sifre-sifirla/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('sifre-sifirla', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

// Authenticated Routes
Route::middleware('auth')->group(function () {
    Route::post('cikis', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    // Bildirim zili (Dalga 27): her rol icin ortak
    Route::get('bildirimler', [\App\Http\Controllers\NotificationController::class, 'index'])
        ->name('notifications.index');
});
