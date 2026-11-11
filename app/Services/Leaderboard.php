<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Consumption;
use App\Models\ExamResult;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Liderlik tablosu (7 Kasim 2026).
 *
 * Puan SAKLANMAZ: her acilista onayli verilerden hesaplanir. Ayri bir puan
 * tablosu, oturum reddedildiginde ya da adisyon geri alindiginda ikinci bir
 * dogruluk kaynagi olarak yalan soylerdi. Agirliklar config('kafe.puan').
 *
 *  - Calisma: StudyStats ile ayni dakika (onayli, esikten uzun, mola dusulmus).
 *  - Deneme: exam_date donemin icindeyse; katilim + basari + gelisim.
 *  - Alisveris: geri alinmamis siparisin odenen tutari, gunluk tavanli.
 *
 * Gizlilik (README SS6.1-4, 7 Kasim'da sahibin istegiyle degisti): diger
 * uyeler yalnizca kisaltilmis ad ("Ayse K.") ve toplam puani gorur; netler ve
 * puan dokumu yalnizca uyenin kendisine ve yoneticiye.
 */
class Leaderboard
{
    public function __construct(private StudyStats $stats)
    {
    }

    /**
     * Donem penceresi: [bas, son) UTC ve gosterim bilgileri.
     *
     * @return array{donem:string,baslangic:string,from:Carbon,to:Carbon,etiket:string,onceki:string,sonraki:?string}
     */
    public static function pencere(string $donem, mixed $tarih): array
    {
        $donem = $donem === 'ay' ? 'ay' : 'hafta';
        $bugun = LocalDay::today();

        try {
            $gun = is_string($tarih) && trim($tarih) !== ''
                ? Carbon::parse($tarih, LocalDay::timezone())
                : Carbon::parse($bugun, LocalDay::timezone());
        } catch (\Throwable) {
            $gun = Carbon::parse($bugun, LocalDay::timezone());
        }

        if ($donem === 'ay') {
            $bas = $gun->copy()->startOfMonth();
            $son = $bas->copy()->addMonthNoOverflow();
            $etiket = $bas->locale('tr')->translatedFormat('F Y');
            $onceki = $bas->copy()->subMonthNoOverflow();
        } else {
            $bas = $gun->copy()->startOfWeek(Carbon::MONDAY);
            $son = $bas->copy()->addWeek();
            $bitis = $son->copy()->subDay();
            $etiket = $bas->locale('tr')->translatedFormat('j M') . ' – ' . $bitis->locale('tr')->translatedFormat('j M Y');
            $onceki = $bas->copy()->subWeek();
        }

        // Gelecek donem yok: tablo bos olurdu.
        $sonraki = $son->toDateString() <= $bugun ? $son->toDateString() : null;

        return [
            'donem' => $donem,
            'baslangic' => $bas->toDateString(),
            'from' => $bas->copy()->utc(),
            'to' => $son->copy()->utc(),
            'etiket' => $etiket,
            'onceki' => $onceki->toDateString(),
            'sonraki' => $sonraki,
        ];
    }

    /**
     * Puani olan uyeler, buyukten kucuge. Esitlikte daha cok calisan, sonra ad.
     *
     * @return Collection<int,array{sira:int,user:User,toplam:int,calisma:int,deneme:int,alisveris:int,dakika:int,deneme_sayisi:int}>
     */
    public function satirlar(Carbon $from, Carbon $to): Collection
    {
        $uyeler = User::where('role', Role::Student->value)
            ->where('subscription_status', 'active')
            ->get(['id', 'name', 'role', 'subscription_status'])
            ->keyBy('id');

        if ($uyeler->isEmpty()) {
            return collect();
        }

        $idler = $uyeler->keys()->map(fn ($id) => (int) $id)->all();
        $p = config('kafe.puan');

        $dakika = $this->stats->minutesBetweenForMany($idler, $from, $to);
        $deneme = $this->denemePuanlari($idler, $from, $to);
        $alisveris = $this->alisverisPuanlari($idler, $from, $to);

        return $uyeler->map(function (User $uye) use ($dakika, $deneme, $alisveris, $p) {
            $dk = $dakika[$uye->id] ?? 0;
            $calisma = intdiv($dk, max(1, (int) $p['calisma_dakika']));
            $d = $deneme[$uye->id] ?? ['puan' => 0, 'sayi' => 0];
            $a = $alisveris[$uye->id] ?? 0;

            return [
                'user' => $uye,
                'toplam' => $calisma + $d['puan'] + $a,
                'calisma' => $calisma,
                'deneme' => $d['puan'],
                'alisveris' => $a,
                'dakika' => $dk,
                'deneme_sayisi' => $d['sayi'],
            ];
        })
            ->filter(fn (array $s) => $s['toplam'] > 0)
            ->sort(fn (array $a, array $b) => [$b['toplam'], $b['dakika'], $a['user']->name] <=> [$a['toplam'], $a['dakika'], $b['user']->name])
            ->values()
            ->map(fn (array $s, int $i) => ['sira' => $i + 1] + $s);
    }

    /**
     * Diger uyelere gorunen ad: ilk ad + soyadinin bas harfi ("Ayse K.").
     * Tek kelimelik ad oldugu gibi kalir.
     */
    public static function kisaAd(string $ad): string
    {
        $parca = preg_split('/\s+/u', trim($ad), -1, PREG_SPLIT_NO_EMPTY) ?: [$ad];

        if (count($parca) === 1) {
            return $parca[0];
        }

        // Turkce buyuk harf: mb_strtoupper('i') "I" verir, "İ" degil.
        $harf = strtr(mb_substr(end($parca), 0, 1), ['i' => 'İ', 'ı' => 'I']);

        return $parca[0] . ' ' . mb_strtoupper($harf) . '.';
    }

    /**
     * Donemde yapilan denemeler (exam_date yerel tarih). Gelisim: ayni
     * turdeki bir onceki denemenin netinden yuksekse.
     *
     * @param  list<int>  $idler
     * @return array<int,array{puan:int,sayi:int}>
     */
    private function denemePuanlari(array $idler, Carbon $from, Carbon $to): array
    {
        $p = config('kafe.puan');
        $basTarih = $from->copy()->timezone(LocalDay::timezone())->toDateString();
        $sonTarih = $to->copy()->timezone(LocalDay::timezone())->toDateString();

        // Gelisim icin onceki denemeler de gerekiyor: donem sonuna kadarki hepsi.
        $sonuclar = ExamResult::whereIn('student_id', $idler)
            ->whereHas('event', fn ($q) => $q->where('exam_date', '<', $sonTarih))
            ->with(['event:id,exam_date,exam_type', 'subjects'])
            ->get()
            ->sortBy(fn (ExamResult $r) => $r->event->exam_date->toDateString() . '#' . str_pad((string) $r->id, 10, '0', STR_PAD_LEFT));

        $puan = [];
        $onceki = [];

        foreach ($sonuclar as $sonuc) {
            $uye = (int) $sonuc->student_id;
            $tur = $sonuc->event->exam_type?->value ?? 'diger';
            $net = $sonuc->totalNet();
            $tarih = $sonuc->event->exam_date->toDateString();

            if ($tarih >= $basTarih) {
                $soru = (int) $sonuc->subjects->sum(fn ($s) => (int) $s->correct + (int) $s->wrong + (int) $s->blank);
                $basari = $soru > 0 ? (int) round(max(0, $net) / $soru * (int) $p['deneme_basari_azami']) : 0;
                $gelisim = isset($onceki[$uye][$tur]) && $net > $onceki[$uye][$tur] ? (int) $p['deneme_gelisim'] : 0;

                $puan[$uye] ??= ['puan' => 0, 'sayi' => 0];
                $puan[$uye]['puan'] += (int) $p['deneme_katilim'] + $basari + $gelisim;
                $puan[$uye]['sayi']++;
            }

            $onceki[$uye][$tur] = $net;
        }

        return $puan;
    }

    /**
     * Odenen tutar (pakete dahil olan 0 TL) gun gun; her gun tavanli.
     *
     * @param  list<int>  $idler
     * @return array<int,int>
     */
    private function alisverisPuanlari(array $idler, Carbon $from, Carbon $to): array
    {
        $p = config('kafe.puan');
        $birim = max(1, (int) $p['alisveris_tl']);
        $tavan = (int) $p['alisveris_gunluk_azami'];

        $gunluk = Consumption::whereIn('user_id', $idler)
            ->where('is_undone', false)
            ->where('consumed_at', '>=', $from)
            ->where('consumed_at', '<', $to)
            ->get(['user_id', 'total_price', 'consumed_at'])
            ->groupBy(fn (Consumption $c) => $c->user_id . '|' . LocalDay::of($c->consumed_at))
            ->map(fn (Collection $g) => min($tavan, intdiv((int) floor((float) $g->sum('total_price')), $birim)));

        $puan = [];
        foreach ($gunluk as $anahtar => $deger) {
            $uye = (int) explode('|', (string) $anahtar)[0];
            $puan[$uye] = ($puan[$uye] ?? 0) + $deger;
        }

        return $puan;
    }
}
