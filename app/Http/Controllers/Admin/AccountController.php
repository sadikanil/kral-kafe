<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\MonthlyBill;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MonthlyAccounts;
use App\Services\SubscriptionRenewer;
use App\Support\ExamCalendar;
use App\Support\LocalDay;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Hesaplar (21 Kasim 2026): ay ay, uye uye paket ve adisyon AYRI.
 *
 * Isletme istegi: paket ve adisyon her ayin basinda yenilensin; harcama
 * (adisyon) ve paket odemeleri ayri kontrol edilebilsin; kapanan ay icin
 * borc ve odeme uye bazinda filtrelenebilsin.
 *
 * Filtreler: ay (YYYY-MM; uye seciliyse "tum" = o uyenin son 24 ayi),
 * uye, tur (hepsi/paket/adisyon), durum (hepsi/borclu/odendi). Odeme
 * mevcut uclara gider (paket: subscriptions.payments.store, adisyon:
 * bills.payments.store) ve geri bu sayfaya doner.
 */
class AccountController extends Controller
{
    private const TURLER = ['hepsi', 'paket', 'adisyon'];

    private const DURUMLAR = ['hepsi', 'borclu', 'odendi'];

    public function index(Request $request, MonthlyAccounts $hesaplar, SubscriptionRenewer $yenileyici): View
    {
        // Ayin 1'i gece isi kacmis olsa da bu ayin paketleri gorunsun.
        $yenileyici->renewDue();

        $buAy = Carbon::parse(LocalDay::today())->startOfMonth();
        $uyeler = User::where('role', Role::Student->value)->orderBy('name')->get(['id', 'name']);
        $uye = $uyeler->firstWhere('id', $request->integer('uye')) ?? null;
        $tumAylar = $uye !== null && $request->query('ay') === 'tum';
        $ay = $tumAylar ? null : ($this->ay($request->query('ay')) ?? $buAy);
        $tur = in_array($request->query('tur'), self::TURLER, true) ? $request->query('tur') : 'hepsi';
        $durum = in_array($request->query('durum'), self::DURUMLAR, true) ? $request->query('durum') : 'hepsi';

        [$from, $to] = $tumAylar ? [$buAy->copy()->subMonths(23), $buAy] : [$ay, $ay];

        $satirlar = $hesaplar->rows($from, $to, $uye?->id)
            ->filter(fn (array $s) => $this->turaUyar($s, $tur) && $this->durumaUyar($s, $tur, $durum))
            ->sortBy(fn (array $s) => $tumAylar ? -$s['ay']->timestamp : mb_strtolower($s['user']->name))
            ->values();

        return view('admin.accounts.index', [
            'rows' => $satirlar,
            'totals' => $this->toplamlar($satirlar),
            'month' => $ay,
            'monthLabel' => $ay ? ExamCalendar::monthLabel($ay->year, $ay->month) : null,
            'isClosed' => $ay !== null && $ay->lessThan($buAy),
            'allMonths' => $tumAylar,
            'months' => $this->aylar($buAy),
            'members' => $uyeler,
            'member' => $uye,
            'type' => $tur,
            'status' => $durum,
            'packageMethods' => PaymentMethod::cases(),
            'billMethods' => [PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer],
            'today' => LocalDay::today(),
        ]);
    }

    private function turaUyar(array $s, string $tur): bool
    {
        return match ($tur) {
            'paket' => $s['paket'] !== null,
            'adisyon' => $s['adisyon'] !== null && $s['adisyon']['tutar'] > 0,
            default => true,
        };
    }

    private function durumaUyar(array $s, string $tur, string $durum): bool
    {
        if ($durum === 'hepsi') {
            return true;
        }

        $kalan = match ($tur) {
            'paket' => $s['paket']['kalan'] ?? 0,
            'adisyon' => $s['adisyon']['kalan'] ?? 0,
            default => $s['kalan'],
        };

        return $durum === 'borclu' ? $kalan > 0 : $kalan <= 0;
    }

    /** @param Collection<int,array> $satirlar */
    private function toplamlar(Collection $satirlar): array
    {
        $topla = fn (string $tur, string $alan) => round((float) $satirlar->sum(fn ($s) => $s[$tur][$alan] ?? 0), 2);

        return [
            'paket' => ['tutar' => $topla('paket', 'tutar'), 'odenen' => $topla('paket', 'odenen'), 'kalan' => $topla('paket', 'kalan')],
            'adisyon' => ['tutar' => $topla('adisyon', 'tutar'), 'odenen' => $topla('adisyon', 'odenen'), 'kalan' => $topla('adisyon', 'kalan')],
            'kalan' => round((float) $satirlar->sum('kalan'), 2),
            'borclu' => $satirlar->filter(fn ($s) => $s['kalan'] > 0)->pluck('user.id')->unique()->count(),
        ];
    }

    /** Ilk paket/adisyon ayindan bu aya, en yeni basta (en fazla 24). */
    private function aylar(Carbon $buAy): Collection
    {
        $ilk = collect([Subscription::min('starts_on'), MonthlyBill::min('bill_month')])
            ->filter()->map(fn ($t) => Carbon::parse($t)->startOfMonth())->min() ?? $buAy;
        $ilk = $ilk->greaterThan($buAy) ? $buAy : $ilk->max($buAy->copy()->subMonths(23));

        $aylar = collect();
        for ($ay = $buAy->copy(); $ay->greaterThanOrEqualTo($ilk); $ay->subMonth()) {
            $aylar->push($ay->copy());
        }

        return $aylar;
    }

    /** ?ay=YYYY-MM -> ayin 1'i; bos ya da bozuksa null. */
    private function ay(mixed $deger): ?Carbon
    {
        return is_string($deger) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $deger, $m)
            ? Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay()
            : null;
    }
}
