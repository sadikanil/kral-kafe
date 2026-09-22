<?php

namespace App\Http\Controllers\ParentPanel;

use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Models\StudyGoal;
use App\Models\StudySession;
use App\Models\User;
use App\Services\StudySessionService;
use App\Services\StudyStats;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Veli paneli - SALT OKUNUR (Dalga 6, MVP #8).
 *
 * Veli hicbir sey degistiremez: oturum bitiremez, hedef koyamaz, tuketim
 * eklemez. Bu yuzden burada tek bir POST rotasi yok; eklenmemeli de.
 *
 * Sinir iki katmanda: liste User::visibleTo(veli) ile, tekil sayfa
 * UserPolicy::viewStudy ile. Ikisi de User::accessibleStudentIds()'e delege
 * eder (bkz. oradaki aciklama - global scope bilerek yok).
 *
 * Gorunen alanlar FEATURE 4'un MVP listesi: gelis/cikis saatleri,
 * gunluk/haftalik/aylik sure, devamlilik, hedef ilerlemesi. Tuketim ve para
 * bilerek YOK - o alan ogrencinin; veliye acilmasi ayri bir karar.
 */
class DashboardController extends Controller
{
    public function __construct(
        private StudyStats $istatistik,
        private StudySessionService $oturumlar,
    ) {
    }

    public function index(): View
    {
        $veli = Auth::user();

        $ogrenciler = User::visibleTo($veli)
            ->orderBy('name')
            ->get()
            ->map(fn (User $ogrenci) => $this->ozet($ogrenci));

        return view('parent.dashboard', [
            'students' => $ogrenciler,
            'upcomingExams' => ExamEvent::upcoming()->limit(3)->get(),
            // Bildirimler (Dalga 11): teslim kanali su an yalnizca panel.
            // E-posta gelince ayni kayitlarin uzerine binecek.
            'notifications' => \App\Models\Notification::for(auth()->user())
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function show(User $student): View
    {
        Gate::authorize('viewStudy', $student);

        // Bagli kullanici ogrenci degilse (yonetici, baska bir veli) burada
        // gosterilecek veri yok; policy gecse bile 404.
        abort_unless($student->isStudent(), 404);

        return view('parent.student', [
            'summary' => $this->ozet($student),
            'days' => $this->sonGunler($student, 14),
            'sessions' => $this->sonOturumlar($student, 30),
            // Deneme sonuclari (Dalga 12). Karar 2: profile islenen her sey
            // veliye acik - netler, siralamalar ve yoneticinin notu dahil.
            // Haftalik plan ilerlemesi (Dalga 13): veli oran gorur.
            'planProgress' => \App\Models\StudyPlanItem::weeklyProgress(
                $student,
                \App\Support\LocalDay::weekStart(\App\Support\LocalDay::today()),
            ),
            'examResults' => \App\Models\ExamResult::where('student_id', $student->id)
                ->with(['event', 'subjects.subject'])
                ->get()
                ->sortByDesc(fn ($sonuc) => $sonuc->event->exam_date)
                ->values(),
        ]);
    }

    /**
     * Bir ogrencinin kart ozeti. Panel ve detay ayni ozeti gosterir.
     *
     * @return array<string,mixed>
     */
    private function ozet(User $ogrenci): array
    {
        $acik = $this->oturumlar->openFor($ogrenci);
        $acik?->load('table');

        // Model::tap() sorgu kurucusuna gider; acikca cagiriliyor.
        $abonelik = $ogrenci->currentSubscription();
        $abonelik?->syncPaymentStatus();

        return [
            'student' => $ogrenci,
            'openSession' => $acik,
            'lastSession' => $acik ? null : StudySession::where('student_id', $ogrenci->id)
                ->countable()
                ->with('table')
                ->orderByDesc('started_at')
                ->first(),
            'todayMinutes' => $this->istatistik->todayMinutes($ogrenci),
            'weekMinutes' => $this->istatistik->weekMinutes($ogrenci),
            'monthMinutes' => $this->istatistik->monthMinutes($ogrenci),
            'streak' => $this->istatistik->streak($ogrenci),
            'weeklyGoal' => StudyGoal::activeFor($ogrenci, LocalDay::today()),
            // Paket ve odeme durumu: odemeyi genelde veli yapar.
            'subscription' => $abonelik,
        ];
    }

    /**
     * Son N yerel gunun dakika dokumu, eskiden yeniye.
     *
     * @return list<array{date:string,minutes:int,attended:bool}>
     */
    private function sonGunler(User $ogrenci, int $gunSayisi): array
    {
        $esik = (int) config('kafe.sayilabilir_dakika');
        $gun = Carbon::parse(LocalDay::today(), LocalDay::timezone())->subDays($gunSayisi - 1);
        $gunler = [];

        for ($i = 0; $i < $gunSayisi; $i++) {
            $tarih = $gun->toDateString();
            $dakika = $this->istatistik->minutesOnDay($ogrenci, $tarih);

            $gunler[] = [
                'date' => $tarih,
                'minutes' => $dakika,
                'attended' => $dakika >= $esik,
            ];

            $gun->addDay();
        }

        return $gunler;
    }

    /**
     * Son oturumlar: acik olan + sayilabilir (onayli) kapali olanlar.
     *
     * Esikten kisa oturumlar (yanlis okutma) listelenmez: veli "cocugum
     * 1 dakika kalmis" diye yanlis alarma girmemeli. Kayit silinmez,
     * yalnizca bu ekrana girmez - Dalga 3'teki kuralin devami.
     *
     * ACIK oturum onaysiz da gorunur ve bu bilincli: "su an iceride" bir
     * VARLIK bilgisi, kredilendirilmis bir sure iddiasi degil. Veliden
     * gizlemek panelin en cok bakilan satirini yok ederdi. Kapanan oturum
     * ise yoneticinin onayina kadar listeye girmez (Dalga 9).
     */
    private function sonOturumlar(User $ogrenci, int $adet)
    {
        return StudySession::where('student_id', $ogrenci->id)
            ->where(function ($q) {
                $q->whereNull('ended_at')
                    ->orWhere(fn ($onayli) => $onayli->countable());
            })
            ->with('table')
            ->orderByDesc('started_at')
            ->limit($adet)
            ->get();
    }
}
