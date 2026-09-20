<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\ConsumptionController;
use App\Http\Controllers\User\DashboardController as UserDashboardController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\LiveController;
use App\Http\Controllers\Admin\StudyTableController;
use App\Http\Controllers\Study\SessionController;
use App\Http\Controllers\Study\TableSessionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\ParentPanel\DashboardController as ParentDashboardController;

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

// Masa QR okutma ve calisma oturumu.
// {table:qr_code} bagi kodu dogrudan sutunla eslestirir; bilinmeyen kod 404.
Route::middleware(['auth', 'subscription'])->group(function () {
    Route::get('/masa/{table:qr_code}', [TableSessionController::class, 'show'])->name('table.scan');
    Route::post('/masa/{table:qr_code}/basla', [TableSessionController::class, 'start'])->name('table.session.start');
    Route::post('/oturum/bitir', [SessionController::class, 'end'])->name('session.end');
});

// Authenticated User Routes
Route::middleware(['auth', 'subscription'])->prefix('kullanici')->name('user.')->group(function () {
    Route::get('/panel', [UserDashboardController::class, 'index'])->name('dashboard');
    Route::get('/gecmis', [UserDashboardController::class, 'history'])->name('history');

    // Consumption API
    Route::post('/tuketim', [ConsumptionController::class, 'store'])->name('consume.store');
    Route::post('/tuketim/{consumption}/geri-al', [ConsumptionController::class, 'undo'])->name('consume.undo');
    Route::get('/tuketim/ozet', [ConsumptionController::class, 'getCurrentMonthSummary'])->name('consume.summary');
});

// Veli paneli - salt okunur, yalnizca GET (Dalga 6).
// Abonelik middleware'i YOK: abonelik ogrencinin, velinin degil.
Route::middleware(['auth', 'role:parent'])->prefix('veli')->name('parent.')->group(function () {
    Route::get('/', [ParentDashboardController::class, 'index'])->name('dashboard');
    Route::get('/ogrenci/{student}', [ParentDashboardController::class, 'show'])->name('student');
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

    // Canli ekran: su an iceride kim, hangi masada, ne kadardir
    Route::get('/canli', [LiveController::class, 'index'])->name('live');

    // Masalar (locations'tan ayri: raf/dolap degil, ogrencinin oturdugu yer)
    Route::get('/masalar-qr-yazdir', [StudyTableController::class, 'printQr'])->name('tables.print-qr');
    Route::resource('masalar', StudyTableController::class)->except(['show'])->names([
        'index' => 'tables.index',
        'create' => 'tables.create',
        'store' => 'tables.store',
        'edit' => 'tables.edit',
        'update' => 'tables.update',
        'destroy' => 'tables.destroy',
    ])->parameters(['masalar' => 'table']);
    Route::get('/masalar/{table}/qr', [StudyTableController::class, 'qr'])->name('tables.qr');
    Route::post('/masalar/{table}/durum', [StudyTableController::class, 'toggleStatus'])->name('tables.toggle-status');

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
