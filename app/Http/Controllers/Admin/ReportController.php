<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consumption;
use App\Models\MonthlyBill;
use App\Models\User;
use App\Services\BillingService;
use App\Support\LocalDay;
use App\Support\Period;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    protected BillingService $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Show reports dashboard.
     */
    public function index()
    {
        [$year, $month] = LocalDay::yearMonth();

        // Monthly stats
        $monthlyRevenue = Consumption::inLocalMonth($year, $month)
            ->where('is_undone', false)
            ->sum('total_price');

        $monthlyItems = Consumption::inLocalMonth($year, $month)
            ->where('is_undone', false)
            ->sum('quantity');

        $activeConsumers = Consumption::inLocalMonth($year, $month)
            ->where('is_undone', false)
            ->distinct('user_id')
            ->count('user_id');

        $avgPerUser = $activeConsumers > 0 ? $monthlyRevenue / $activeConsumers : 0;

        $stats = [
            'monthly_revenue' => $monthlyRevenue,
            'monthly_items' => $monthlyItems,
            'active_consumers' => $activeConsumers,
            'avg_per_user' => $avgPerUser,
        ];

        // Recent bills
        $recentBills = MonthlyBill::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Top consumers this month
        $topConsumers = Consumption::selectRaw('user_id, SUM(quantity) as total_items, SUM(total_price) as total_spent')
            ->inLocalMonth($year, $month)
            ->where('is_undone', false)
            ->groupBy('user_id')
            ->orderByDesc('total_spent')
            ->limit(10)
            ->with('user')
            ->get();

        return view('admin.reports.index', [
            // Secicilerin ve baglantilarin varsayilani kartlarla AYNI ay:
            // gorunumdeki date('n') UTC ayiydi, 1'inin ilk 3 saatinde kartlar
            // Ekim'i gosterirken baglantilar Eylul'e gidiyordu.
            'year' => $year,
            'month' => $month,
            'stats' => $stats,
            'recentBills' => $recentBills,
            'topConsumers' => $topConsumers,
        ]);
    }

    /**
     * Generate monthly bills.
     */
    public function generateBills(Request $request)
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2030'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $bills = $this->billingService->generateMonthlyBills(
            $validated['year'],
            $validated['month']
        );

        return redirect()->route('admin.reports.monthly', [
            'year' => $validated['year'],
            'month' => $validated['month'],
        ])->with('success', $bills->count() . ' fatura oluşturuldu.');
    }

    /**
     * Show monthly report.
     */
    public function monthly(Request $request)
    {
        [$year, $month] = $this->donem($request);

        $bills = MonthlyBill::with('user')
            ->whereYear('bill_month', $year)
            ->whereMonth('bill_month', $month)
            ->orderBy('total_amount', 'desc')
            ->paginate(50);

        $totalAmount = MonthlyBill::whereYear('bill_month', $year)
            ->whereMonth('bill_month', $month)
            ->sum('total_amount');

        $totalItems = MonthlyBill::whereYear('bill_month', $year)
            ->whereMonth('bill_month', $month)
            ->sum('total_items');

        return view('admin.reports.monthly', [
            'currentYear' => LocalDay::yearMonth()[0],
            'bills' => $bills,
            'year' => $year,
            'month' => $month,
            'totalAmount' => $totalAmount,
            'totalItems' => $totalItems,
        ]);
    }

    /**
     * Export monthly summary as CSV.
     */
    public function exportSummary(Request $request)
    {
        [$year, $month] = $this->donem($request);

        $csv = $this->billingService->exportToCsv($year, $month);

        $filename = "kral-kafe-ozet-{$year}-{$month}.csv";

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Export detailed consumption report as CSV.
     */
    public function exportDetailed(Request $request)
    {
        [$year, $month] = $this->donem($request);

        $csv = $this->billingService->exportDetailedToCsv($year, $month);

        $filename = "kral-kafe-detay-{$year}-{$month}.csv";

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Show user-specific report.
     */
    public function userReport(User $user, Request $request)
    {
        [$year, $month] = $this->donem($request);

        $summary = $this->billingService->getUserMonthlySummary($user, $year, $month);

        return view('admin.reports.user', [
            'currentYear' => LocalDay::yearMonth()[0],
            'user' => $user,
            'summary' => $summary,
            'year' => $year,
            'month' => $month,
        ]);
    }

    /**
     * Adresteki donem; verilmezse KAFE ayi.
     *
     * Period::normalize'in yedegi Carbon::now(), yani UTC ayi: yerel ayin
     * ilk 3 saatinde parametresiz rapor, CSV ve ogrenci raporu bir onceki
     * ayi aciyordu. Bos ya da eksik deger once kafe ayina tamamlanir.
     *
     * @return array{0:int,1:int}
     */
    private function donem(Request $request): array
    {
        [$yil, $ay] = LocalDay::yearMonth();

        return Period::normalize($request->get('year') ?? $yil, $request->get('month') ?? $ay);
    }
}
