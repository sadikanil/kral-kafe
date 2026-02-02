<?php

namespace App\Services;

use App\Models\User;
use App\Models\Consumption;
use App\Models\MonthlyBill;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class BillingService
{
    /**
     * Generate monthly bills for all active users.
     */
    public function generateMonthlyBills(int $year, int $month): Collection
    {
        $users = User::where('role', 'student')
            ->where('subscription_status', 'active')
            ->get();

        $bills = collect();

        foreach ($users as $user) {
            $bill = MonthlyBill::generateForUserMonth($user, $year, $month);
            $bills->push($bill);
        }

        return $bills;
    }

    /**
     * Get monthly consumption summary for a user.
     */
    public function getUserMonthlySummary(User $user, int $year, int $month): array
    {
        $consumptions = Consumption::with('product', 'location')
            ->where('user_id', $user->id)
            ->whereYear('consumed_at', $year)
            ->whereMonth('consumed_at', $month)
            ->where('is_undone', false)
            ->orderBy('consumed_at', 'desc')
            ->get();

        $byProduct = $consumptions->groupBy('product_id')->map(function ($items) {
            $first = $items->first();
            return [
                'product_id' => $first->product_id,
                'product_name' => $first->product->name,
                'quantity' => $items->sum('quantity'),
                'total' => $items->sum('total_price'),
            ];
        })->values();

        $byDay = $consumptions->groupBy(function ($item) {
            return $item->consumed_at->format('Y-m-d');
        })->map(function ($items, $date) {
            return [
                'date' => $date,
                'formatted_date' => Carbon::parse($date)->format('d.m.Y'),
                'count' => $items->sum('quantity'),
                'total' => $items->sum('total_price'),
            ];
        })->values();

        return [
            'user' => $user,
            'year' => $year,
            'month' => $month,
            'month_name' => $this->getTurkishMonthName($month),
            'consumptions' => $consumptions,
            'by_product' => $byProduct,
            'by_day' => $byDay,
            'total_items' => $consumptions->sum('quantity'),
            'total_amount' => $consumptions->sum('total_price'),
        ];
    }

    /**
     * Export monthly bills to CSV.
     */
    public function exportToCsv(int $year, int $month): string
    {
        $bills = MonthlyBill::with('user')
            ->whereYear('bill_month', $year)
            ->whereMonth('bill_month', $month)
            ->orderBy('user_id')
            ->get();

        $csv = "Kullanıcı ID,İsim,E-posta,Toplam Ürün,Toplam Tutar\n";

        foreach ($bills as $bill) {
            $csv .= implode(',', [
                $bill->user_id,
                '"' . $bill->user->name . '"',
                $bill->user->email,
                $bill->total_items,
                number_format($bill->total_amount, 2, '.', ''),
            ]) . "\n";
        }

        return $csv;
    }

    /**
     * Export detailed consumption report to CSV.
     */
    public function exportDetailedToCsv(int $year, int $month): string
    {
        $consumptions = Consumption::with('user', 'product', 'location')
            ->whereYear('consumed_at', $year)
            ->whereMonth('consumed_at', $month)
            ->where('is_undone', false)
            ->orderBy('consumed_at')
            ->get();

        $csv = "Tarih,Kullanıcı,Ürün,Lokasyon,Adet,Birim Fiyat,Toplam\n";

        foreach ($consumptions as $c) {
            $csv .= implode(',', [
                $c->consumed_at->format('d.m.Y H:i'),
                '"' . $c->user->name . '"',
                '"' . $c->product->name . '"',
                '"' . $c->location->name . '"',
                $c->quantity,
                number_format($c->unit_price, 2, '.', ''),
                number_format($c->total_price, 2, '.', ''),
            ]) . "\n";
        }

        return $csv;
    }

    /**
     * Get Turkish month name.
     */
    private function getTurkishMonthName(int $month): string
    {
        $months = [
            1 => 'Ocak',
            2 => 'Şubat',
            3 => 'Mart',
            4 => 'Nisan',
            5 => 'Mayıs',
            6 => 'Haziran',
            7 => 'Temmuz',
            8 => 'Ağustos',
            9 => 'Eylül',
            10 => 'Ekim',
            11 => 'Kasım',
            12 => 'Aralık'
        ];


        return $months[$month] ?? '';
    }

    /**
     * Get billing statistics for dashboard.
     */
    public function getDashboardStats(): array
    {
        $currentMonth = now()->month;
        $currentYear = now()->year;

        $thisMonthTotal = Consumption::whereYear('consumed_at', $currentYear)
            ->whereMonth('consumed_at', $currentMonth)
            ->where('is_undone', false)
            ->sum('total_price');

        $thisMonthItems = Consumption::whereYear('consumed_at', $currentYear)
            ->whereMonth('consumed_at', $currentMonth)
            ->where('is_undone', false)
            ->sum('quantity');

        $activeUsers = User::where('role', 'student')
            ->where('subscription_status', 'active')
            ->count();

        $todayTotal = Consumption::whereDate('consumed_at', today())
            ->where('is_undone', false)
            ->sum('total_price');

        return [
            'this_month_total' => $thisMonthTotal,
            'this_month_items' => $thisMonthItems,
            'active_users' => $activeUsers,
            'today_total' => $todayTotal,
        ];
    }
}
