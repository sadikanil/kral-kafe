<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Consumption;
use App\Models\ExamEvent;
use App\Services\BillingService;
use App\Models\StudyGoal;
use App\Models\StudySession;
use App\Services\StudySessionService;
use App\Services\StudyStats;
use App\Support\LocalDay;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    protected BillingService $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Show user dashboard.
     */
    public function index(StudyStats $istatistik)
    {
        $user = Auth::user();

        // Current month summary
        $currentMonthTotal = $user->getCurrentMonthTotal();
        $currentMonthItems = $user->getCurrentMonthItemCount();

        // Recent consumptions
        $recentConsumptions = Consumption::with('product', 'location')
            ->where('user_id', $user->id)
            ->where('is_undone', false)
            ->orderBy('consumed_at', 'desc')
            ->limit(20)
            ->get();

        // Monthly summary for current year
        $monthlySummary = $this->billingService->getUserMonthlySummary(
            $user,
            now()->year,
            now()->month
        );

        return view('user.dashboard', [
            'user' => $user,
            // Acik calisma oturumu: ogrenci masaya donmeden panelden bitirebilsin.
            'openSession' => app(StudySessionService::class)->openFor($user),
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
            // Calisma plani (Dalga 13; aylik donem Dalga 14). Hedefin
            // yaninda "ne" sorusunun cevabi. Iki donem ayri listeleniyor
            // cunku "bu hafta" ile "bu ay" farkli aciliyor: haftalik madde
            // bugun icin, aylik madde ayin geneli icin anlamli.
            'planItems' => \App\Models\StudyPlanItem::forPeriod(
                $user,
                \App\Enums\PlanPeriod::Week,
                \App\Enums\PlanPeriod::Week->startFor(LocalDay::today()),
            )->with('subject')->orderBy('id')->get(),
            // Paylasilan koc notlari (Dalga 14b). Ogrenci veliyle AYNI
            // kumeyi gorur (SS6.1-3): gizli izleme yok.
            'coachNotes' => \App\Models\CoachNote::forStudent($user)
                ->shared()
                ->with('author')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(),
            'monthlyPlanItems' => \App\Models\StudyPlanItem::forPeriod(
                $user,
                \App\Enums\PlanPeriod::Month,
                \App\Enums\PlanPeriod::Month->startFor(LocalDay::today()),
            )->with('subject')->orderBy('id')->get(),
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
            'currentMonthTotal' => $currentMonthTotal,
            'currentMonthItems' => $currentMonthItems,
            'recentConsumptions' => $recentConsumptions,
            'monthlySummary' => $monthlySummary,
        ]);
    }
}
