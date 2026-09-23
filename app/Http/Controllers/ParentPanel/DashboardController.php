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
            // Resmi sinav geri sayimi (Dalga 15b). Hatirlaticidan AYRI:
            // YKS bir deneme degil, hedefin kendisi.
            'officialExam' => ExamEvent::upcomingOfficial()->first(),
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
            'studyLogs' => \App\Models\StudyLog::recentFor($student),
            // Ozel ders (Dalga 25): onumuzdeki iki hafta, yalnizca Tier 3.
            'lessons' => $student->entitlements()->privateLessons
                ? \App\Support\PrivateLessonCalendar::between(
                    $student, \App\Support\LocalDay::today(),
                    \Illuminate\Support\Carbon::parse(\App\Support\LocalDay::today())->addWeeks(2)->toDateString())
                : [],
            // Deneme sonuclari (Dalga 12). Karar 2: profile islenen her sey
            // veliye acik - netler, siralamalar ve yoneticinin notu dahil.
            // Calisma plani (Dalga 13, Dalga 14'te aylik eklendi).
            //
            // Veli artik yalnizca ORANI degil MADDELERI de goruyor: karar 2
            // (profile islenen her sey veliye acik) oran icin de maddeler
            // icin de gecerli, ve "3/5" tek basina velinin cocuguyla
            // konusmasina yetmiyor - neyin yapildigi da gorunmeli.
            'planPeriods' => collect(\App\Enums\PlanPeriod::cases())
                ->map(fn (\App\Enums\PlanPeriod $donem) => [
                    'period' => $donem,
                    'start' => $donem->startFor(\App\Support\LocalDay::today()),
                    'items' => \App\Models\StudyPlanItem::forPeriod(
                        $student,
                        $donem,
                        $donem->startFor(\App\Support\LocalDay::today()),
                    )->with('subject')->orderBy('id')->get(),
                ])
                ->filter(fn (array $blok) => $blok['items']->isNotEmpty())
                ->values(),
            // Paylasilan koc notlari (Dalga 14b). Ozel notlar shared()
            // scope'unda suzuluyor - sablon gizlilik karari vermiyor.
            'weakTopics' => \App\Models\WeakTopic::forStudent($student)
                ->open()
                ->with('subject')
                ->orderByDesc('created_at')
                ->get(),
            'coachNotes' => \App\Models\CoachNote::forStudent($student)
                ->shared()
                ->with('author')
                ->orderByDesc('created_at')
                ->get(),
            'examResults' => \App\Models\ExamResult::where('student_id', $student->id)
                ->with(['event', 'subjects.subject'])
                ->get()
                ->sortByDesc(fn ($sonuc) => $sonuc->event->exam_date)
                ->values(),
        ]);
    }

    /**
     * Haftalik rapor (Dalga 15a).
     *
     * Ayni policy kapisi: veli yalnizca kendi cocugunun raporunu acar.
     */
    public function report(\Illuminate\Http\Request $request, User $student, \App\Services\WeeklyReportBuilder $uretici): View
    {
        Gate::authorize('viewStudy', $student);
        abort_unless($student->isStudent(), 404);

        $hafta = \App\Support\WeekParameter::resolve($request->query('hafta'));

        return view('parent.report', [
            'student' => $student,
            'hafta' => $hafta,
            'report' => $uretici->for($student, $hafta),
            'finished' => $uretici->isFinished($hafta),
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
