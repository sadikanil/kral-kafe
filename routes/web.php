<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\TabController;
use App\Http\Controllers\User\ExamReportController as UserExamReportController;
use App\Http\Controllers\Admin\ExamReportController as AdminExamReportController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\User\DashboardController as UserDashboardController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\ExamResultController;
use App\Http\Controllers\Admin\LiveController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\Admin\SessionApprovalController;
use App\Http\Controllers\Admin\SettingsController;
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

// QR TUKETIM AKISI DALGA 8'DE KALDIRILDI.
//
// Ogrenci urun eklemek icin QR okutmuyor; self adisyondan (/kullanici/adisyon)
// ekliyor ve stok dusumu orada yapiliyor. Lokasyon QR'lari DURUYOR - onlar
// yoneticinin stok sayimi icin.

// Masa QR okutma ve calisma oturumu.
// {table:qr_code} bagi kodu dogrudan sutunla eslestirir; bilinmeyen kod 404.
Route::middleware(['auth', 'subscription'])->group(function () {
    // Uygulama ici QR okuyucu ve kamerasiz yedek yol (Dalga 10a)
    Route::get('/masa-okut', [TableSessionController::class, 'scanner'])->name('table.scanner');
    Route::post('/masa-bul', [TableSessionController::class, 'find'])->name('table.find');

    Route::get('/masa/{table:qr_code}', [TableSessionController::class, 'show'])->name('table.scan');
    Route::post('/masa/{table:qr_code}/basla', [TableSessionController::class, 'start'])->name('table.session.start');
    Route::post('/oturum/bitir', [SessionController::class, 'end'])->name('session.end');
    // Calisma sayaci (Dalga 23): duraklat, 15 dk mola, ogle arasi, devam
    Route::get('/calisma', [SessionController::class, 'timer'])->name('session.timer');
    Route::post('/oturum/duraklat', [SessionController::class, 'pause'])->name('session.pause');
    Route::post('/oturum/devam', [SessionController::class, 'resume'])->name('session.resume');
    // Calisma kaydi (Dalga 28): "Tarih · 200 soru". Eski ders secimi kalkti;
    // oturumun dersi son kayittan gelir.
    Route::post('/oturum/kayit', [\App\Http\Controllers\Study\StudyLogController::class, 'store'])->name('session.logs.store');
    Route::delete('/oturum/kayit/{log}', [\App\Http\Controllers\Study\StudyLogController::class, 'destroy'])->name('session.logs.destroy');
});

// Authenticated User Routes
Route::middleware(['auth', 'subscription'])->prefix('kullanici')->name('user.')->group(function () {
    Route::get('/panel', [UserDashboardController::class, 'index'])->name('dashboard');
    // Odemeler (Dalga 22): paket bedeli + aylik adisyon dokumu. Eski
    // "Tuketim Gecmisi" bunun icinde; adresi yer imlerinde kalmis olabilir.
    Route::get('/odemeler', [\App\Http\Controllers\StatementController::class, 'student'])->name('payments');
    Route::redirect('/gecmis', '/kullanici/odemeler')->name('history');
    Route::get('/denemeler', [ExamCalendarController::class, 'student'])->name('exams');

    // Ogrencinin KENDI verisi: plan, haftalik rapor, adisyon. Rol kapisi
    // yalnizca bu uclarda, grubun tamaminda degil: panel ogretmen ve
    // gorevlinin ana sayfasi (Role::homeRoute), deneme raporunu da koc ve
    // veli okuyor. Kapi yokken veli /kullanici/rapor'u acinca kendi adina
    // haftalik rapor donduruluyor, adisyondan kendi adina urun ekleyip stok
    // dusurebiliyordu - bu kayitlari hicbir ekran faturalamiyordu.
    Route::middleware('role:student')->group(function () {
        // Haftalik calisma plani (Dalga 13): ogrenci yalnizca tamamlar
        Route::post('/plan/{item}/tamamla', [\App\Http\Controllers\User\StudyPlanController::class, 'complete'])
            ->name('study-plan.complete');
        // Yanlis "✓ Bitti"yi geri alma (QA a11y A15)
        Route::post('/plan/{item}/geri-al', [\App\Http\Controllers\User\StudyPlanController::class, 'reopen'])
            ->name('study-plan.reopen');
        // Takvimli plan (Dalga 30c): kendi haftasi; serbest denemeyi gune koyar.
        Route::get('/plan', [\App\Http\Controllers\User\StudyPlanController::class, 'show'])->name('plan');
        Route::post('/plan/deneme', [\App\Http\Controllers\User\StudyPlanController::class, 'scheduleExam'])->name('plan.exam');
        Route::delete('/plan/deneme/{item}', [\App\Http\Controllers\User\StudyPlanController::class, 'removeExam'])->name('plan.exam.destroy');

        // Kendi haftalik raporu. SS6.1-3: veliye giden ogrenciye de gorunur.
        Route::get('/rapor', [\App\Http\Controllers\User\WeeklyReportController::class, 'show'])->name('report');

        // Self adisyon: QR'siz, panelden urun ekleme
        Route::get('/adisyon', [TabController::class, 'index'])->name('tab');
        Route::post('/adisyon', [TabController::class, 'store'])->name('tab.store');
        Route::post('/adisyon/{consumption}/geri-al', [TabController::class, 'undo'])->name('tab.undo');
    });

    // Deneme sonuclari ve raporlari: yalnizca deneme kulubu (Dalga 19)
    Route::middleware('entitlement:examClub')->group(function () {
        // Deneme sonuclari (Dalga 12): siralamalar ve yonetici notu
        Route::get('/deneme-sonuclari', [\App\Http\Controllers\User\ExamResultController::class, 'index'])
            ->name('exam-results');

        Route::get('/deneme-raporlari', [UserExamReportController::class, 'index'])->name('exam-reports.index');
        Route::get('/deneme-raporlari/{report}', [UserExamReportController::class, 'show'])->name('exam-reports.show');
        Route::get('/deneme-raporlari/{report}/pdf', [UserExamReportController::class, 'pdf'])->name('exam-reports.pdf');
    });

});

// Veli paneli - salt okunur, yalnizca GET (Dalga 6).
// Abonelik middleware'i YOK: abonelik ogrencinin, velinin degil.
Route::middleware(['auth', 'role:parent'])->prefix('veli')->name('parent.')->group(function () {
    Route::get('/', [ParentDashboardController::class, 'index'])->name('dashboard');
    Route::get('/ogrenci/{student}', [ParentDashboardController::class, 'show'])->name('student');
    Route::get('/denemeler', [ExamCalendarController::class, 'parent'])->name('exams');
    Route::get('/ogrenci/{student}/rapor', [ParentDashboardController::class, 'report'])->name('report');
    Route::get('/ogrenci/{student}/odemeler', [\App\Http\Controllers\StatementController::class, 'parent'])->name('payments');
});

// Koc paneli - calisma plani (Dalga 14).
// Abonelik middleware'i YOK: abonelik ogrencinin, kocun degil.
// 'admin' de kabul ediliyor cunku yonetici ayni zamanda koctur (karar 11);
// kendisine ogrenci atanmasi gerekmez, accessibleStudentIds() ona null doner.
Route::middleware(['auth', 'role:coach,admin'])->prefix('koc')->name('coach.')->group(function () {
    Route::get('/plan', [\App\Http\Controllers\Coach\StudyPlanController::class, 'index'])->name('plan.index');
    Route::get('/plan/{student}', [\App\Http\Controllers\Coach\StudyPlanController::class, 'show'])->name('plan.show');
    Route::post('/plan/{student}', [\App\Http\Controllers\Coach\StudyPlanController::class, 'store'])->name('plan.store');
    Route::delete('/plan/maddeler/{item}', [\App\Http\Controllers\Coach\StudyPlanController::class, 'destroy'])->name('plan.destroy');
    // Takvimli plan (Dalga 30c): maddeyi baska gune tasi; haftalik sabit program.
    Route::patch('/plan/maddeler/{item}', [\App\Http\Controllers\Coach\StudyPlanController::class, 'move'])->name('plan.move');
    Route::post('/program/{student}', [\App\Http\Controllers\Coach\CommitmentController::class, 'store'])->name('commitments.store');
    Route::delete('/program/kayit/{commitment}', [\App\Http\Controllers\Coach\CommitmentController::class, 'destroy'])->name('commitments.destroy');

    // Koc notlari ve veli gorusme kaydi (Dalga 14b). Plan ile AYNI sinir
    // (User::canCoach); ayri sayfada cunku her sayfa tek is yapsin.
    Route::get('/notlar/{student}', [\App\Http\Controllers\Coach\NoteController::class, 'index'])->name('notes.index');
    Route::post('/notlar/{student}', [\App\Http\Controllers\Coach\NoteController::class, 'store'])->name('notes.store');
    Route::delete('/notlar/kayit/{note}', [\App\Http\Controllers\Coach\NoteController::class, 'destroy'])->name('notes.destroy');

    // Haftalik veli raporu - koc tarafi (Dalga 15a). Rapor tembel uretiliyor:
    // sayfayi acan ilk kiside hesaplanip saklaniyor, cron gerekmiyor.
    Route::get('/rapor/{student}', [\App\Http\Controllers\Coach\WeeklyReportController::class, 'show'])->name('report');
    Route::post('/rapor/{student}/yorum', [\App\Http\Controllers\Coach\WeeklyReportController::class, 'comment'])->name('report.comment');
    Route::post('/rapor/{student}/yeniden', [\App\Http\Controllers\Coach\WeeklyReportController::class, 'regenerate'])->name('report.regenerate');

    // Zayif konu listesi (Dalga 17b). Plan ile AYNI sinir (User::canCoach).
    Route::get('/konular/{student}', [\App\Http\Controllers\Coach\WeakTopicController::class, 'index'])->name('topics.index');
    Route::post('/konular/{student}', [\App\Http\Controllers\Coach\WeakTopicController::class, 'store'])->name('topics.store');
    Route::post('/konular/kayit/{topic}/kapat', [\App\Http\Controllers\Coach\WeakTopicController::class, 'close'])->name('topics.close');
    Route::post('/konular/kayit/{topic}/ac', [\App\Http\Controllers\Coach\WeakTopicController::class, 'reopen'])->name('topics.reopen');
    Route::post('/konular/kayit/{topic}/plana', [\App\Http\Controllers\Coach\WeakTopicController::class, 'plan'])->name('topics.plan');
    Route::delete('/konular/kayit/{topic}', [\App\Http\Controllers\Coach\WeakTopicController::class, 'destroy'])->name('topics.destroy');
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
    // Dalga 18b: sifre silinir, kullanici her yerden cikar, yeni sifre belirler
    Route::post('/kullanicilar/{user}/sifre-sifirla', [UserController::class, 'resetPassword'])->name('users.reset-password');

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

    // Lokasyonlar sayfasi kalkti (Dalga 29): konum urunun etiketi.

    // Koc atamasi (Dalga 14). Atamayi YALNIZCA yonetici yapar; kocun kendine
    // ogrenci atayabilmesi atamanin anlamini ortadan kaldirirdi.
    //
    // Plan yazma uclari Dalga 14'te buradan /koc/plan altina TASINDI: plan
    // artik kendi sayfasinda ve koc da yaziyor. Ayni formu iki yerde
    // tutmak, birinin gunun birinde digerinden farkli davranmasi demekti.
    Route::post('/kullanicilar/{student}/koc', [\App\Http\Controllers\Admin\CoachAssignmentController::class, 'attach'])
        ->name('coaches.attach');
    Route::delete('/kullanicilar/{student}/koc/{coach}', [\App\Http\Controllers\Admin\CoachAssignmentController::class, 'detach'])
        ->name('coaches.detach');

    // Ozel ders (Dalga 25): Tier 3, yalnizca yonetici duzenler
    Route::post('/kullanicilar/{student}/ozel-ders', [\App\Http\Controllers\Admin\PrivateLessonController::class, 'store'])->name('lessons.store');
    Route::post('/ozel-ders/{slot}/iptal', [\App\Http\Controllers\Admin\PrivateLessonController::class, 'cancel'])->name('lessons.cancel');
    Route::post('/ozel-ders/{slot}/tasi', [\App\Http\Controllers\Admin\PrivateLessonController::class, 'move'])->name('lessons.move');
    Route::delete('/ozel-ders/{slot}', [\App\Http\Controllers\Admin\PrivateLessonController::class, 'destroy'])->name('lessons.destroy');

    // Dersler sayfasi kalkti (Dalga 30d): liste mufredattan (sinif + alan).

    // Deneme sonucu girisi (Dalga 12)
    Route::get('/denemeler/{examEvent}/ogrenci/{student}/sonuc', [ExamResultController::class, 'edit'])
        ->name('exam-results.edit');
    Route::post('/denemeler/{examEvent}/ogrenci/{student}/sonuc', [ExamResultController::class, 'store'])
        ->name('exam-results.store');

    // Ayarlar (Dalga 10b): panelden degistirilen kafe geneli ayarlar
    Route::get('/ayarlar', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::post('/ayarlar/konum', [SettingsController::class, 'saveLocation'])->name('settings.location');

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
    Route::post('/kullanicilar/{user}/paket-degistir', [SubscriptionController::class, 'switch'])->name('subscriptions.switch');
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
    // Dalga 29: stok urunler altinda - stok tablosu, toplu giris, sayim.
    Route::get('/stok', [StockController::class, 'index'])->name('stock.index');
    Route::post('/stok', [StockController::class, 'update'])->name('stock.update');
    Route::get('/stok/sayim', [StockController::class, 'counts'])->name('stock.counts');
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

/*
|--------------------------------------------------------------------------
| Zamanlanmis isler (Dalga 11)
|--------------------------------------------------------------------------
|
| Vercel Cron bu adresi cagiriyor. Giris gerektirmiyor - gizli anahtarla
| korunuyor (bkz. CronController). Anahtar tanimsizsa uc hic calismaz.
|
| /api ALTINDA DEGIL: Vercel PHP'yi /api/index.php'den calistiriyor ve
| Symfony /api onekini kok dizin sayip yoldan kesiyor; /api/... rotasi
| canlida hic eslesmiyordu.
|
*/
Route::get('/zamanlanmis/gunluk', [CronController::class, 'daily'])->name('cron.daily');
