<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\ConsumptionController;
use App\Http\Controllers\User\DashboardController as UserDashboardController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\ReportController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Home - redirect based on role
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route(auth()->user()->homeRoute());
    }

    return redirect()->route('login');
})->name('home');

// QR Code Consumption Route (Public for scanning, but requires auth)
Route::get('/tuketim/{qrCode}', [ConsumptionController::class, 'showLocation'])
    ->middleware(['auth', 'subscription'])
    ->name('consume.location');

// Authenticated User Routes
Route::middleware(['auth', 'subscription'])->prefix('kullanici')->name('user.')->group(function () {
    Route::get('/panel', [UserDashboardController::class, 'index'])->name('dashboard');
    Route::get('/gecmis', [UserDashboardController::class, 'history'])->name('history');

    // Consumption API
    Route::post('/tuketim', [ConsumptionController::class, 'store'])->name('consume.store');
    Route::post('/tuketim/{consumption}/geri-al', [ConsumptionController::class, 'undo'])->name('consume.undo');
    Route::get('/tuketim/ozet', [ConsumptionController::class, 'getCurrentMonthSummary'])->name('consume.summary');
});

// Admin Routes
Route::middleware(['auth', 'admin'])->prefix('yonetim')->name('admin.')->group(function () {
    // Dashboard
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    // Users
    Route::resource('kullanicilar', UserController::class)->names([
        'index' => 'users.index',
        'create' => 'users.create',
        'store' => 'users.store',
        'edit' => 'users.edit',
        'update' => 'users.update',
        'destroy' => 'users.destroy',
    ])->parameters(['kullanicilar' => 'user']);
    Route::post('/kullanicilar/{user}/durum', [UserController::class, 'toggleStatus'])->name('users.toggle-status');

    // Products
    Route::resource('urunler', ProductController::class)->names([
        'index' => 'products.index',
        'create' => 'products.create',
        'store' => 'products.store',
        'edit' => 'products.edit',
        'update' => 'products.update',
        'destroy' => 'products.destroy',
    ])->parameters(['urunler' => 'product']);
    Route::post('/urunler/{product}/durum', [ProductController::class, 'toggleStatus'])->name('products.toggle-status');

    // Locations
    Route::resource('lokasyonlar', LocationController::class)->names([
        'index' => 'locations.index',
        'create' => 'locations.create',
        'store' => 'locations.store',
        'show' => 'locations.show',
        'edit' => 'locations.edit',
        'update' => 'locations.update',
        'destroy' => 'locations.destroy',
    ])->parameters(['lokasyonlar' => 'location']);
    Route::get('/lokasyonlar/{location}/qr', [LocationController::class, 'showQr'])->name('locations.qr');
    Route::post('/lokasyonlar/{location}/durum', [LocationController::class, 'toggleStatus'])->name('locations.toggle-status');
    Route::get('/lokasyonlar-qr-yazdir', [LocationController::class, 'printQrCodes'])->name('locations.print-qr');

    // Stock Management
    Route::get('/stok', [StockController::class, 'index'])->name('stock.index');
    Route::get('/stok/{location}/kayit', [StockController::class, 'capture'])->name('stock.capture');
    Route::post('/stok/{location}/yukle', [StockController::class, 'uploadPhotos'])->name('stock.upload');
    // batch_id Postgres'te uuid tipi; gecersiz bir deger sorguya ulasirsa
    // SQLSTATE[22P02] ile 500 doner.
    Route::get('/stok/{location}/analiz/{batch}', [StockController::class, 'analyze'])
        ->whereUuid('batch')
        ->name('stock.analyze');
    Route::post('/stok/{location}/onayla', [StockController::class, 'confirm'])->name('stock.confirm');
    Route::get('/stok/tutarsizlik/{discrepancy}', [StockController::class, 'discrepancy'])->name('stock.discrepancy');
    Route::post('/stok/tutarsizlik/{discrepancy}/coz', [StockController::class, 'resolveDiscrepancy'])->name('stock.resolve-discrepancy');

    // Reports
    Route::get('/raporlar', [ReportController::class, 'index'])->name('reports.index');
    Route::post('/raporlar/fatura-olustur', [ReportController::class, 'generateBills'])->name('reports.generate-bills');
    Route::get('/raporlar/aylik', [ReportController::class, 'monthly'])->name('reports.monthly');
    Route::get('/raporlar/indir-ozet', [ReportController::class, 'exportSummary'])->name('reports.export-summary');
    Route::get('/raporlar/indir-detay', [ReportController::class, 'exportDetailed'])->name('reports.export-detailed');
    Route::get('/raporlar/kullanici/{user}', [ReportController::class, 'userReport'])->name('reports.user');
});

// Auth Routes
require __DIR__ . '/auth.php';
