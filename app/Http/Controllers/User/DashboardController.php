<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Models\StudyGoal;
use App\Models\StudySession;
use App\Services\StudySessionService;
use App\Services\StudyStats;
use App\Support\LocalDay;
use App\Support\WeekPlan;
use Illuminate\Support\Facades\Auth;

/**
 * Ogrenci paneli. UX turu (23 Eyl): "calisma once" - ustte oturum ya da
 * baslat dugmesi, sonra BUGUN (takvimin bugunu), sonra sureler. Para
 * kartlari ve tuketim listesi Adisyon'da ve Odemeler'de; panelde tekrar
 * etmiyor.
 */
class DashboardController extends Controller
{
    public function index(StudyStats $istatistik)
    {
        $user = Auth::user();

        return view('user.dashboard', [
            'user' => $user,
            // Acik calisma oturumu: ogrenci masaya donmeden panelden bitirebilsin.
            'openSession' => app(StudySessionService::class)->openFor($user),
            // Masasiz paket (yalnizca deneme) okuyucuya giremez; panel
            // onu oraya cagirmasin.
            'canScan' => $user->entitlements()->table,
            // Bugun: sabit program, ozel ders, deneme ve plan maddeleri.
            // Planim'daki takvimle AYNI hesap (WeekPlan) ve ayni parca.
            'today' => collect(WeekPlan::for($user, LocalDay::today()))->firstWhere('isToday', true),
            // Calisma istatistikleri (Dalga 5). Hedef yoksa null gecer ve
            // ilerleme cubugu hic cizilmez - bos bir cubuk "hedefin yok" demez,
            // "hedefin var ama hic calismadin" der.
            'todayMinutes' => $istatistik->todayMinutes($user),
            'weekMinutes' => $istatistik->weekMinutes($user),
            'monthMinutes' => $istatistik->monthMinutes($user),
            'streak' => $istatistik->streak($user),
            'weeklyGoal' => StudyGoal::activeFor($user, LocalDay::today()),
            // Ders etiketi ve kirilimi (Dalga 17a).
            // Acik zayif konular (Dalga 17b). Kapanmislar listeden cikar.
            'weakTopics' => \App\Models\WeakTopic::forStudent($user)
                ->open()
                ->with('subject')
                ->orderByDesc('created_at')
                ->get(),
            'subjectBreakdown' => $istatistik->minutesBySubject(
                $user,
                ...LocalDay::weekBounds(LocalDay::today()),
            ),
            // Paylasilan koc notlari (Dalga 14b). Ogrenci veliyle AYNI
            // kumeyi gorur (SS6.1-3): gizli izleme yok.
            'coachNotes' => \App\Models\CoachNote::forStudent($user)
                ->shared()
                ->with('author')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(),
            // Onay bekleyen / reddedilen oturumlar (Dalga 9): yukaridaki
            // sureler yalnizca ONAYLI oturumlari sayiyor. Bu liste olmadan
            // ogrenci calistigi halde sifir goruyor ve sebebini bilmiyor.
            'notCredited' => StudySession::where('student_id', $user->id)
                ->notCredited()
                ->with('table')
                ->orderByDesc('ended_at')
                ->limit(5)
                ->get(),
            // Deneme takvimi hatirlaticisi: siradaki deneme(ler).
            'upcomingExams' => ExamEvent::upcoming()->limit(3)->get(),
            // Resmi sinav geri sayimi (Dalga 15b). Hatirlaticidan AYRI:
            // YKS bir deneme degil, hedefin kendisi.
            'officialExam' => ExamEvent::upcomingOfficial()->first(),
            'subscription' => $user->currentSubscription(),
        ]);
    }
}
