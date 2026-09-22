<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Consumption;
use App\Models\ExamEvent;
use App\Services\BillingService;
use App\Models\StudyGoal;
use App\Services\StudySessionService;
use App\Services\StudyStats;
use App\Support\LocalDay;
use App\Support\SqlDialect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            // Deneme takvimi hatirlaticisi: siradaki deneme(ler).
            'upcomingExams' => ExamEvent::upcoming()->limit(3)->get(),
            'subscription' => $user->currentSubscription(),
            'currentMonthTotal' => $currentMonthTotal,
            'currentMonthItems' => $currentMonthItems,
            'recentConsumptions' => $recentConsumptions,
            'monthlySummary' => $monthlySummary,
        ]);
    }

    /**
     * Show consumption history.
     */
    public function history(Request $request)
    {
        $user = Auth::user();

        // Parse month parameter (format: YYYY-MM)
        $monthParam = $request->get('month');
        if ($monthParam) {
            $parts = explode('-', $monthParam);
            $year = (int) $parts[0];
            $month = (int) ($parts[1] ?? now()->month);
        } else {
            $year = now()->year;
            $month = now()->month;
        }

        // Get consumptions for the selected period
        $consumptionsQuery = Consumption::with(['product', 'location'])
            ->where('user_id', $user->id)
            ->where('is_undone', false);

        if ($monthParam) {
            $consumptionsQuery->whereYear('consumed_at', $year)
                ->whereMonth('consumed_at', $month);
        }

        $consumptions = $consumptionsQuery->orderBy('consumed_at', 'desc')
            ->paginate(20);

        $summary = $this->billingService->getUserMonthlySummary($user, $year, $month);

        // Get available months
        $availableMonths = Consumption::where('user_id', $user->id)
            ->selectRaw($this->yearMonthSelect())
            ->groupBy('year', 'month')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return view('user.history', [
            'user' => $user,
            'consumptions' => $consumptions,
            'summary' => $summary,
            'year' => $year,
            'month' => $month,
            'availableMonths' => $availableMonths,
        ]);
    }

    /**
     * Year/month extraction for the "available months" filter.
     */
    private function yearMonthSelect(): string
    {
        return SqlDialect::yearMonth(DB::connection()->getDriverName(), 'consumed_at');
    }
}
