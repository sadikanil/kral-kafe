<?php

namespace App\Support;

use App\Models\Consumption;
use App\Models\MonthlyBill;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Adisyonum sayfasinin verisi (DEV-506, 26 Ekim 2026).
 *
 * Bir uyenin bir aylik adisyonu: ucretli toplam, kalemler (urun basina),
 * son eklenenler ve onceki aylar. Tutar aylik faturayla AYNI kural:
 * geri alinmamis kayitlarin total_price toplami (pakete dahil adetler
 * zaten dusulmus - PaymentStatement::spendingTotal ile ayni). Ay kafe
 * saatine gore (LocalDay).
 */
final class TabSummary
{
    /** Onceki aylar listesi bu kadar geriye bakar. */
    private const GECMIS_AY = 12;

    /** Bu ayin "son eklenenler" listesi. */
    private const SON = 5;

    /**
     * @return array{
     *   year:int, month:int, monthLabel:string, isCurrent:bool,
     *   total:float, itemCount:int,
     *   lines:Collection<int,array{name:string,emoji:?string,quantity:int,covered:int,total:float}>,
     *   latest:Collection<int,Consumption>, bill:?MonthlyBill,
     *   history:Collection<int,array{key:string,label:string,total:float,itemCount:int}>
     * }
     */
    public static function for(User $uye, int $yil, int $ay): array
    {
        [$buYil, $buAy] = LocalDay::yearMonth();
        // Gelecek ay istenirse bu ay: bos bir sayfa yaniltir.
        if ($yil * 12 + $ay > $buYil * 12 + $buAy) {
            [$yil, $ay] = [$buYil, $buAy];
        }
        $buAyMi = $yil === $buYil && $ay === $buAy;

        $kayitlar = Consumption::with('product')
            ->where('user_id', $uye->id)
            ->where('is_undone', false)
            ->inLocalMonth($yil, $ay)
            ->orderByDesc('consumed_at')->orderByDesc('id')
            ->get();

        return [
            'year' => $yil,
            'month' => $ay,
            'monthLabel' => ExamCalendar::monthLabel($yil, $ay),
            'isCurrent' => $buAyMi,
            'total' => round((float) $kayitlar->sum('total_price'), 2),
            'itemCount' => (int) $kayitlar->sum('quantity'),
            'lines' => $kayitlar->groupBy('product_id')->map(fn (Collection $grup) => [
                'name' => $grup->first()->product?->name ?? '—',
                'emoji' => $grup->first()->product?->emoji,
                'quantity' => (int) $grup->sum('quantity'),
                'covered' => (int) $grup->sum('covered_quantity'),
                'total' => round((float) $grup->sum('total_price'), 2),
            ])->sortByDesc('total')->values(),
            'latest' => $buAyMi ? $kayitlar->take(self::SON)->values() : collect(),
            'bill' => MonthlyBill::where('user_id', $uye->id)
                ->whereDate('bill_month', Carbon::create($yil, $ay, 1)->toDateString())
                ->first(),
            'history' => self::gecmis($uye, $buYil, $buAy),
        ];
    }

    /**
     * Bu aydan onceki, adisyonu olan aylar (yeniden eskiye). Gruplama PHP'de:
     * ay sinirlari kafe saatine gore ve SQL'de surucuye gore degisirdi.
     *
     * @return Collection<int,array{key:string,label:string,total:float,itemCount:int}>
     */
    private static function gecmis(User $uye, int $buYil, int $buAy): Collection
    {
        $bas = Carbon::create($buYil, $buAy, 1)->subMonths(self::GECMIS_AY);
        [$aralikBasi] = LocalDay::monthBounds($bas->year, $bas->month);
        [$buAyBasi] = LocalDay::monthBounds($buYil, $buAy);
        $tz = LocalDay::timezone();

        return Consumption::where('user_id', $uye->id)
            ->where('is_undone', false)
            ->where('consumed_at', '>=', $aralikBasi)
            ->where('consumed_at', '<', $buAyBasi)
            ->get(['consumed_at', 'quantity', 'total_price'])
            ->groupBy(fn (Consumption $k) => $k->consumed_at->copy()->timezone($tz)->format('Y-m'))
            ->map(fn (Collection $grup, string $anahtar) => [
                'key' => $anahtar,
                'label' => ExamCalendar::monthLabel(...array_map('intval', explode('-', $anahtar))),
                'total' => round((float) $grup->sum('total_price'), 2),
                'itemCount' => (int) $grup->sum('quantity'),
            ])
            ->sortKeysDesc()->values();
    }

    public static function money(float $tutar): string
    {
        return number_format($tutar, 2, ',', '.') . ' ₺';
    }
}
