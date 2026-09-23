<?php

namespace App\Services;

use App\Models\User;
use App\Models\Consumption;
use App\Models\MonthlyBill;
use App\Models\Subscription;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class BillingService
{
    /**
     * Ayin faturalari: bugun aktif olanlar ARTI o ay tuketimi ya da o ay
     * baslayan paketi olan her ogrenci.
     *
     * subscription_status bir erisim anahtaridir, ticari gercek degil (README
     * SS9.2). Yalnizca bugun aktif olanlari secmek, Agustos'ta tuketip sonra
     * askiya alinan ogrencinin Agustos borcunu hic faturalamamak demekti; ozet
     * CSV de detay CSV'siyle tutmuyordu.
     */
    public function generateMonthlyBills(int $year, int $month): Collection
    {
        $users = User::where('role', 'student')
            ->where(fn ($q) => $q->where('subscription_status', 'active')
                ->orWhereHas('consumptions', fn ($c) => $c->inLocalMonth($year, $month)->where('is_undone', false))
                ->orWhereIn('id', Subscription::startingIn($year, $month)->select('student_id')))
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
            ->inLocalMonth($year, $month)
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
            return \App\Support\LocalDay::of($item->consumed_at);
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

        $csv = $this->csvSatiri(['Kullanıcı ID', 'İsim', 'E-posta', 'Toplam Ürün', 'Tüketim Tutarı', 'Paket Tutarı', 'Genel Toplam']);

        foreach ($bills as $bill) {
            $csv .= $this->csvSatiri([
                $bill->user_id,
                $bill->user->name,
                $bill->user->email,
                $bill->total_items,
                number_format($bill->total_amount, 2, '.', ''),
                number_format((float) $bill->package_amount, 2, '.', ''),
                number_format($bill->grandTotal(), 2, '.', ''),
            ]);
        }

        return $csv;
    }

    /**
     * Export detailed consumption report to CSV.
     */
    public function exportDetailedToCsv(int $year, int $month): string
    {
        $consumptions = Consumption::with('user', 'product', 'location')
            ->inLocalMonth($year, $month)
            ->where('is_undone', false)
            ->orderBy('consumed_at')
            ->get();

        $csv = $this->csvSatiri(['Tarih', 'Kullanıcı', 'Ürün', 'Lokasyon', 'Adet', 'Birim Fiyat', 'Toplam']);

        foreach ($consumptions as $c) {
            $csv .= $this->csvSatiri([
                $c->consumed_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i'),
                $c->user->name,
                $c->product->name,
                $c->location->name,
                $c->quantity,
                number_format($c->unit_price, 2, '.', ''),
                number_format($c->total_price, 2, '.', ''),
            ]);
        }

        return $csv;
    }

    /**
     * Tek CSV satiri, fputcsv kacislariyla.
     *
     * Adlar elle '"' . $ad . '"' ile sariliyordu: icindeki cift tirnak
     * ikilenmedigi icin 'Ali "Kral" Ozturk' ya da 'Tost "Karisik", buyuk'
     * satiri fazladan sutuna boluyor, tablo sagdan kayiyordu. Kacis karakteri
     * bos (''): PHP'nin standart disi ters bolu kacisi kapali, yalnizca
     * RFC 4180 tirnak ikilemesi.
     *
     * @param  array<int,string|int|float|null>  $alanlar
     */
    private function csvSatiri(array $alanlar): string
    {
        $akis = fopen('php://temp', 'r+');
        fputcsv($akis, $alanlar, ',', '"', '');
        rewind($akis);
        $satir = stream_get_contents($akis);
        fclose($akis);

        return $satir;
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
        $thisMonthTotal = Consumption::currentMonth()
            ->where('is_undone', false)
            ->sum('total_price');

        $thisMonthItems = Consumption::currentMonth()
            ->where('is_undone', false)
            ->sum('quantity');

        $activeUsers = User::where('role', 'student')
            ->where('subscription_status', 'active')
            ->count();

        $todayTotal = Consumption::onLocalDay(\App\Support\LocalDay::today())
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
