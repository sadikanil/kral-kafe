<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\ConsumptionController;
use App\Http\Controllers\User\TabController;
use App\Http\Controllers\User\ExamReportController as UserExamReportController;
use App\Http\Controllers\Admin\ExamReportController as AdminExamReportController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\User\DashboardController as UserDashboardController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\LiveController;
use App\Http\Controllers\Admin\SessionApprovalController;
use App\Http\Controllers\Admin\StudyTableController;
use App\Http\Controllers\Study\SessionController;
use App\Http\Controllers\Study\TableSessionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ExamEventController;
use App\Http\Controllers\Study\ExamCalendarController;
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
    Route::get('/denemeler', [ExamCalendarController::class, 'student'])->name('exams');

    // Deneme sonuc raporlari (PDF + yapay zeka analizi), salt okunur
    Route::get('/deneme-raporlari', [UserExamReportController::class, 'index'])->name('exam-reports.index');
    Route::get('/deneme-raporlari/{report}', [UserExamReportController::class, 'show'])->name('exam-reports.show');
    Route::get('/deneme-raporlari/{report}/pdf', [UserExamReportController::class, 'pdf'])->name('exam-reports.pdf');

    // Self adisyon: QR'siz, panelden urun ekleme
    Route::get('/adisyon', [TabController::class, 'index'])->name('tab');
    Route::post('/adisyon', [TabController::class, 'store'])->name('tab.store');
    Route::post('/adisyon/{consumption}/geri-al', [TabController::class, 'undo'])->name('tab.undo');

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
    Route::get('/denemeler', [ExamCalendarController::class, 'parent'])->name('exams');
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

    // Oturum onay kuyrugu (Dalga 9) - kuyruk canli ekranin icinde yasiyor
    Route::post('/oturumlar/toplu-onay', [SessionApprovalController::class, 'approveMany'])
        ->name('sessions.approve-many');
    Route::post('/oturumlar/{session}/onayla', [SessionApprovalController::class, 'approve'])
        ->name('sessions.approve');
    Route::post('/oturumlar/{session}/reddet', [SessionApprovalController::class, 'reject'])
        ->name('sessions.reject');

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

    // Paketler ve odeme (Dalga 7)
    Route::resource('paketler', PackageController::class)->except(['show', 'destroy'])->names([
        'index' => 'packages.index',
        'create' => 'packages.create',
        'store' => 'packages.store',
        'edit' => 'packages.edit',
        'update' => 'packages.update',
    ])->parameters(['paketler' => 'package']);
    Route::post('/paketler/{package}/durum', [PackageController::class, 'toggleStatus'])->name('packages.toggle-status');
    Route::get('/odemeler', [SubscriptionController::class, 'overview'])->name('subscriptions.overview');
    Route::get('/kullanicilar/{user}/abonelikler', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('/kullanicilar/{user}/abonelikler', [SubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::post('/abonelikler/{subscription}/iptal', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
    Route::post('/abonelikler/{subscription}/odeme', [SubscriptionController::class, 'storePayment'])->name('subscriptions.payments.store');
    Route::delete('/odemeler/{payment}', [SubscriptionController::class, 'destroyPayment'])->name('subscriptions.payments.destroy');

    // Deneme sonuc raporlari: ogrenci basina PDF yukleme + yapay zeka analizi
    Route::get('/kullanicilar/{user}/deneme-raporlari', [AdminExamReportController::class, 'index'])->name('exam-reports.index');
    Route::post('/kullanicilar/{user}/deneme-raporlari', [AdminExamReportController::class, 'store'])->name('exam-reports.store');
    Route::post('/deneme-raporlari/{report}/analiz', [AdminExamReportController::class, 'analyze'])->name('exam-reports.analyze');
    Route::delete('/deneme-raporlari/{report}', [AdminExamReportController::class, 'destroy'])->name('exam-reports.destroy');
    Route::get('/deneme-raporlari/{report}/pdf', [AdminExamReportController::class, 'pdf'])->name('exam-reports.pdf');

    // Deneme sinavi takvimi (kafe geneli)
    Route::resource('denemeler', ExamEventController::class)->except(['show'])->names([
        'index' => 'exams.index',
        'create' => 'exams.create',
        'store' => 'exams.store',
        'edit' => 'exams.edit',
        'update' => 'exams.update',
        'destroy' => 'exams.destroy',
    ])->parameters(['denemeler' => 'exam']);

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
