<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Consumption;
use App\Services\BillingService;
use Illuminate\Http\Request;
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
    public function index()
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
            ->selectRaw('YEAR(consumed_at) as year, MONTH(consumed_at) as month')
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
}
