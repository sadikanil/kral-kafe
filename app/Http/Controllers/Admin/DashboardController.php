<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Models\Location;
use App\Models\Consumption;
use App\Models\DiscrepancyLog;
use App\Services\BillingService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected BillingService $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Show admin dashboard.
     */
    public function index()
    {
        $stats = $this->billingService->getDashboardStats();

        // Additional stats
        $totalProducts = Product::where('is_active', true)->count();
        $totalLocations = Location::where('is_active', true)->count();
        $unresolvedDiscrepancies = DiscrepancyLog::where('resolved', false)->count();

        // Today's consumptions
        $todayConsumptions = Consumption::with('user', 'product', 'location')
            ->onLocalDay(\App\Support\LocalDay::today())
            ->where('is_undone', false)
            ->orderBy('consumed_at', 'desc')
            ->limit(10)
            ->get();

        // Top products this month
        $topProducts = Consumption::selectRaw('product_id, SUM(quantity) as total_quantity, SUM(total_price) as total_revenue')
            ->currentMonth()
            ->where('is_undone', false)
            ->groupBy('product_id')
            ->orderByDesc('total_quantity')
            ->limit(5)
            ->with('product')
            ->get();

        return view('admin.dashboard', [
            'stats' => $stats,
            'totalProducts' => $totalProducts,
            'totalLocations' => $totalLocations,
            'unresolvedDiscrepancies' => $unresolvedDiscrepancies,
            'todayConsumptions' => $todayConsumptions,
            'topProducts' => $topProducts,
        ]);
    }
}
