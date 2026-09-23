<?php

namespace App\Services;

use App\Models\StudySession;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calisma sureleri, devamlilik ve gun kirilimlari.
 *
 * Iki kural her seyi belirliyor:
 *
 * 1. GUN SINIRI YEREL. Sutunlar UTC ama "14 Eylul" demek Istanbul'da 14 Eylul
 *    00:00-23:59 demek. whereDate/whereMonth UTC gunune baktigi icin yerel
 *    00:00-03:00 arasindaki her oturum bir onceki gune duserdi - gunun %12,5'i.
 *
 * 2. GECE YARISINI ASAN OTURUM TEK SATIR. Bolme burada, hesap tarafinda
 *    yapilir: 22:00-01:30 arasi bir oturum birinci gune 120, ikinci gune 90
 *    dakika yazar. Veriyi bolerek saklamak (her gun icin ayri satir) oturumu
 *    parcalar ve "kac oturum actin" sorusunu cevaplanamaz hale getirirdi.
 *
 * Cok kisa oturumlar (yanlis okutma) hicbir toplama girmez - Dalga 3'teki
 * kuralin devami. Esik: config('kafe.sayilabilir_dakika').
 *
 * 3. YALNIZCA ONAYLI OTURUM SAYILIR (Dalga 9). Biten oturum yoneticinin onay
 *    kuyruguna duser; onaylanana kadar sure, seri ve hedef ilerlemesi onu
 *    gormez. Ogrencinin o anki acik oturumu da buraya girmez - o, panelde
 *    ayri bir canli karttir.
 *
 * 4. SURE NET (QA hata 6). Molalar (duraklat, 15 dk, ogle arasi) dusulur;
 *    sayac ve duration_minutes ile ayni sayi.
 */
class StudyStats
{
    /**
     * Seri icin tek sorguda okunan gun sayisi (QA perf P3).
     *
     * streak() eskiden gun basina bir sorgu aciyordu: 60 gunluk seri 60
     * gidis-donus demekti. Pencere hafta ve ay basini da kapsamali (en fazla
     * 31 gun geri), summary() ayni okumadan besleniyor.
     */
    private const SERI_PENCERE = 60;

    /**
     * Bir yerel gune dusen dakika.
     */
    public function minutesOnDay(User $student, string $date): int
    {
        [$bas] = LocalDay::bounds($date);
        [$son] = LocalDay::bounds(
            Carbon::parse($date, LocalDay::timezone())->addDay()->toDateString()
        );

        return $this->minutesBetween($student, $bas, $son);
    }

    /**
     * Iki an arasina dusen dakika. Oturumlarin YALNIZCA bu araliga dusen
     * kismi sayilir.
     *
     * Aralik YARI ACIK: [from, to). Kapali aralik kullanmak her gunu bir
     * dakika eksik sayardi - endOfDay() 23:59:59 veriyor ve 22:00'den oraya
     * 119 dakika var, 120 degil. Gun sinirlari bu yuzden "ertesi gunun
     * baslangici" olarak aliniyor.
     */
    public function minutesBetween(User $student, Carbon $from, Carbon $to): int
    {
        return $this->sumMinutes($this->overlapping([$student->id], $from, $to), $from, $to);
    }

    /**
     * Araliktaki dakikalarin DERS KIRILIMI (Dalga 17a).
     *
     * Etiketsiz oturum "Genel" kovasina duser - kaybolmaz. Toplam,
     * minutesBetween() ile ayni kaliyor.
     *
     * Cok calisilan ust sirada: koc ve ogrenci "neye zaman ayirdim"
     * sorusunu ilk satirda gormeli.
     *
     * @return array<string,int>
     */
    public function minutesBySubject(User $student, Carbon $from, Carbon $to): array
    {
        $kova = [];
        $bas = $from->getTimestamp();
        $son = $to->getTimestamp();

        foreach ($this->overlapping([$student->id], $from, $to)->load('subject') as $oturum) {
            $saniye = $this->netSeconds($this->span($oturum), $bas, $son);

            if ($saniye <= 0) {
                continue;
            }

            $ad = $oturum->subject?->name ?? 'Genel';
            $kova[$ad] = ($kova[$ad] ?? 0) + intdiv($saniye, 60);
        }

        arsort($kova);

        return $kova;
    }

    public function todayMinutes(User $student): int
    {
        return $this->minutesOnDay($student, LocalDay::today());
    }

    public function weekMinutes(User $student): int
    {
        return $this->minutesBetween($student, ...$this->weekWindow());
    }

    public function monthMinutes(User $student): int
    {
        return $this->minutesBetween($student, ...$this->monthWindow());
    }

    /**
     * Bugun, bu hafta, bu ay ve seri - TEK okumayla (QA perf P3).
     *
     * @return array{today:int,week:int,month:int,streak:int}
     */
    public function summary(User $student): array
    {
        return $this->summaryForMany([$student->id])[(int) $student->id];
    }

    /**
     * Bircok ogrenci icin summary(). Panel dort ayri metodla bes-alti sorgu,
     * seriyle birlikte gun basina bir sorgu daha aciyordu.
     *
     * Bugun/hafta/ay, oturum oturum ve KENDI penceresiyle hesaplanir; gun
     * kovalarindan toplanmaz. Gece yarisini asan oturumda gun basina asagi
     * yuvarlamak haftaya bir dakika eksik yazabilirdi - tek tek metodlarla
     * (minutesBetween) birebir ayni sayi cikmali.
     *
     * @param  list<int>  $studentIds
     * @return array<int,array{today:int,week:int,month:int,streak:int}>
     */
    public function summaryForMany(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $bugun = LocalDay::today();
        [$gunBas] = LocalDay::bounds($bugun);
        [$yarinBas] = LocalDay::bounds($this->gunSonra($bugun, 1));
        [$haftaBas, $sonrakiHaftaBas] = $this->weekWindow();
        [$ayBas, $sonrakiAyBas] = $this->monthWindow();

        $pencereIlk = min(
            LocalDay::weekStart($bugun),
            LocalDay::monthStart($bugun),
            $this->gunOnce($bugun, self::SERI_PENCERE - 1),
        );
        [$pencereBas] = LocalDay::bounds($pencereIlk);

        // Sonraki hafta/ay basina kadar okumaya gerek yok: onayli oturum
        // bitmis oturumdur, gelecekte baslayan olamaz.
        $oturumlar = $this->overlapping($studentIds, $pencereBas, $yarinBas);
        $gunluk = $this->bucketByDay($studentIds, $oturumlar, $pencereIlk, $bugun);
        $ogrenciye = $oturumlar->groupBy(fn (StudySession $o) => (int) $o->student_id);

        $ozet = [];
        foreach ($studentIds as $id) {
            $id = (int) $id;
            $kendi = $ogrenciye->get($id, collect());

            $ozet[$id] = [
                'today' => $this->sumMinutes($kendi, $gunBas, $yarinBas),
                'week' => $this->sumMinutes($kendi, $haftaBas, $sonrakiHaftaBas),
                'month' => $this->sumMinutes($kendi, $ayBas, $sonrakiAyBas),
                'streak' => $this->streakFrom($id, $gunluk[$id], $pencereIlk),
            ];
        }

        return $ozet;
    }

    /**
     * Araliktaki "gelinmis" gunler (Y-m-d), artan sirada.
     *
     * Gelinmis gun tanimi: o yerel gune esikten fazla dakika dusen gun. Kisa
     * bir yanlis okutma bir gunu "geldi" saymamali.
     *
     * @return array<int,string>
     */
    public function attendedDays(User $student, string $from, string $to): array
    {
        return $this->attendedDaysForMany([$student->id], $from, $to)[$student->id] ?? [];
    }

    /**
     * Bircok ogrenci icin araliktaki gelinmis gunler - TEK sorgu.
     *
     * attendedDays() eskiden gun basina bir sorgu aciyordu; koc listesinde
     * 14 gun x N ogrenci yuzlerce sorgu demekti. Fonksiyon-veritabani
     * mesafesi bu projede bir kez pahaliya mal oldu (README SS10.12).
     *
     * Tanim TEK YERDE kalsin diye attendedDays() artik buraya delege
     * ediyor: iki ayri "gelinmis gun" tanimi, gun gelip birinin
     * digerinden farkli cevap vermesi demekti.
     *
     * @param  list<int>  $studentIds
     * @return array<int,list<string>>
     */
    public function attendedDaysForMany(array $studentIds, string $from, string $to): array
    {
        $esik = (int) config('kafe.sayilabilir_dakika');

        return array_map(
            fn (array $gunler) => array_keys(array_filter($gunler, fn (int $dk) => $dk >= $esik)),
            $this->dailyMinutesForMany($studentIds, $from, $to),
        );
    }

    /**
     * Ogrenci basina gun basina dakika - TEK sorgu.
     *
     * Gece yarisini asan oturum IKI gune de dagitilir; bolme burada
     * yapilir cunku veri tek satir olarak saklaniyor (bkz. sinif basligi,
     * kural 2).
     *
     * @param  list<int>  $studentIds
     * @return array<int,array<string,int>>
     */
    public function dailyMinutesForMany(array $studentIds, string $from, string $to): array
    {
        if ($studentIds === []) {
            return [];
        }

        [$pencereBas] = LocalDay::bounds($from);
        [$pencereSon] = LocalDay::bounds($this->gunSonra($to, 1));

        return $this->bucketByDay(
            $studentIds,
            $this->overlapping($studentIds, $pencereBas, $pencereSon),
            $from,
            $to,
        );
    }

    /**
     * Kesintisiz gelme serisi.
     *
     * Bugun HENUZ gelinmemis olmasi seriyi bozmaz: aksi halde seri her sabah
     * sifirlanir ve ozellik anlamini yitirir. Sayim bugunden geriye gider,
     * bugun bossa dunden baslar.
     */
    public function streak(User $student, int $maxDays = 365): int
    {
        $bugun = LocalDay::today();
        $ilk = $this->gunOnce($bugun, self::SERI_PENCERE - 1);

        return $this->streakFrom(
            (int) $student->id,
            $this->dailyMinutesForMany([$student->id], $ilk, $bugun)[(int) $student->id],
            $ilk,
            $maxDays,
        );
    }

    /**
     * Gun haritasindan seri. Harita $pencereIlk'e kadar kesintisizse bir
     * onceki pencere okunur - seri ancak o kadar uzunsa ek sorgu olur.
     *
     * @param  array<string,int>  $gunler
     */
    private function streakFrom(int $id, array $gunler, string $pencereIlk, int $maxDays = 365): int
    {
        $esik = (int) config('kafe.sayilabilir_dakika');
        $gun = LocalDay::today();

        if (($gunler[$gun] ?? 0) < $esik) {
            $gun = $this->gunOnce($gun, 1);
        }

        $seri = 0;

        while ($seri < $maxDays) {
            if ($gun < $pencereIlk) {
                $pencereIlk = $this->gunOnce($gun, self::SERI_PENCERE - 1);
                $gunler = $this->dailyMinutesForMany([$id], $pencereIlk, $gun)[$id];
            }

            if (($gunler[$gun] ?? 0) < $esik) {
                break;
            }

            $seri++;
            $gun = $this->gunOnce($gun, 1);
        }

        return $seri;
    }

    /**
     * Oturumlari yerel gunlere dagitir.
     *
     * Gun sinirlari pencere basina BIR KEZ tamsayi (unix saniye) olarak
     * kurulur (QA perf P12): eskiden her oturumun her gunu icin Carbon::parse
     * ve saat dilimi cevrimi yapiliyordu; koc listesinde 25 ogrencinin
     * hesabinin buyuk kismi buydu.
     *
     * @param  list<int>  $studentIds
     * @param  Collection<int,StudySession>  $oturumlar
     * @return array<int,array<string,int>>
     */
    private function bucketByDay(array $studentIds, Collection $oturumlar, string $from, string $to): array
    {
        // Bos gunler de anahtar olarak dursun: cagiran taraf "o gun sifir"
        // ile "o gun hic yok" ayrimini yapmak zorunda kalmasin.
        $kova = [];
        foreach ($studentIds as $id) {
            $kova[(int) $id] = [];
        }

        $sinirlar = [];
        $gun = Carbon::parse($from, LocalDay::timezone())->startOfDay();
        while ($gun->toDateString() <= $to) {
            $tarih = $gun->toDateString();
            $bas = $gun->getTimestamp();
            $gun->addDay();
            $sinirlar[$tarih] = [$bas, $gun->getTimestamp()];
        }

        foreach ($oturumlar as $oturum) {
            $span = $this->span($oturum);
            $ilkGun = LocalDay::of($oturum->started_at);
            $sonGun = LocalDay::of(Carbon::createFromTimestamp($span[1]));
            $ogrenciId = (int) $oturum->student_id;

            foreach ($sinirlar as $tarih => [$gunBas, $gunSon]) {
                if ($tarih < $ilkGun) {
                    continue;
                }
                if ($tarih > $sonGun) {
                    break;
                }

                $saniye = $this->netSeconds($span, $gunBas, $gunSon);

                if ($saniye > 0) {
                    $kova[$ogrenciId][$tarih] = ($kova[$ogrenciId][$tarih] ?? 0) + intdiv($saniye, 60);
                }
            }
        }

        // Oturumlar sirasiz geliyor; attendedDays() artan sira vaat ediyor.
        foreach ($kova as $id => $gunler) {
            ksort($gunler);
            $kova[$id] = $gunler;
        }

        return $kova;
    }

    /**
     * Araligi kesen onayli oturumlar, molalariyla.
     *
     * whereBetween sutuna fonksiyon uygulamadigi icin indeks kullanilabilir
     * kalir. Acik oturumlarin ended_at'i null oldugundan ayrica alinir.
     * Molalar birlikte gelir: net sure icin her oturuma ayri sorgu acilmasin.
     *
     * Esikten kisa oturumlar burada elenir - gune bolunmus parcalari degil,
     * oturumun KENDI toplam suresi degerlendirilir.
     *
     * @param  list<int>  $studentIds
     * @return Collection<int,StudySession>
     */
    private function overlapping(array $studentIds, Carbon $from, Carbon $to): Collection
    {
        $esik = (int) config('kafe.sayilabilir_dakika');

        return StudySession::whereIn('student_id', $studentIds)
            ->approved()
            ->where('started_at', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from);
            })
            ->with('pauses')
            ->get()
            ->filter(fn (StudySession $o) => $o->minutesSoFar() >= $esik)
            ->values();
    }

    /** @param  Collection<int,StudySession>  $oturumlar */
    private function sumMinutes(Collection $oturumlar, Carbon $from, Carbon $to): int
    {
        $bas = $from->getTimestamp();
        $son = $to->getTimestamp();

        return $oturumlar->sum(fn (StudySession $o) => intdiv($this->netSeconds($this->span($o), $bas, $son), 60));
    }

    /**
     * Oturumun zaman cizelgesi tamsayi olarak: baslangic, bitis, molalar.
     *
     * Eloquent her started_at/ended_at erisiminde datetime cast'ini yeniden
     * kuruyor; gun gun donen dongude bunu bir kez okumak yetiyor.
     *
     * @return array{0:int,1:int,2:list<array{0:int,1:int}>}
     */
    private function span(StudySession $oturum): array
    {
        $bitis = ($oturum->ended_at ?? now())->getTimestamp();

        $molalar = [];
        foreach ($oturum->pauses as $mola) {
            $molalar[] = [$mola->started_at->getTimestamp(), $mola->ended_at?->getTimestamp() ?? $bitis];
        }

        return [$oturum->started_at->getTimestamp(), $bitis, $molalar];
    }

    /**
     * Oturumun [from, to) penceresine dusen NET saniyesi (QA hata 6).
     *
     * Once pencereyle kesisim alinir, sonra her molanin AYNI kesisime dusen
     * kismi cikarilir. Boylece gece yarisini asan bir ogle arasi da iki gune
     * dogru bolunur. Saniyeyle hesaplanir: minutesSoFar() ile ayni yuvarlama,
     * tek pencerede kalan oturum duration_minutes'a esit cikar.
     *
     * Brut fark (baslangic-bitis) kullanmak ogle arasini onaydan sonra
     * calisma sayiyordu; sayac ve duration_minutes ise net diyordu.
     *
     * @param  array{0:int,1:int,2:list<array{0:int,1:int}>}  $span
     */
    private function netSeconds(array $span, int $from, int $to): int
    {
        [$bas, $bitis, $molalar] = $span;

        // Kesisim: max(baslangiclar) .. min(bitisler)
        $kesBas = max($bas, $from);
        $kesSon = min($bitis, $to);

        if ($kesSon <= $kesBas) {
            return 0;
        }

        $saniye = $kesSon - $kesBas;

        foreach ($molalar as [$molaBas, $molaSon]) {
            $ortakBas = max($molaBas, $kesBas);
            $ortakSon = min($molaSon, $kesSon);

            if ($ortakSon > $ortakBas) {
                $saniye -= $ortakSon - $ortakBas;
            }
        }

        return max(0, $saniye);
    }

    /** @return array{0:Carbon,1:Carbon} bu haftanin [pazartesi, sonraki pazartesi) */
    private function weekWindow(): array
    {
        [$bas] = LocalDay::weekBounds(LocalDay::today());
        [$son] = LocalDay::weekBounds($this->gunSonra(LocalDay::today(), 7));

        return [$bas, $son];
    }

    /** @return array{0:Carbon,1:Carbon} bu ayin [1'i, sonraki ayin 1'i) */
    private function monthWindow(): array
    {
        $bugun = Carbon::parse(LocalDay::today(), LocalDay::timezone());
        $sonraki = $bugun->copy()->addMonthNoOverflow();

        [$bas] = LocalDay::monthBounds($bugun->year, $bugun->month);
        [$son] = LocalDay::monthBounds($sonraki->year, $sonraki->month);

        return [$bas, $son];
    }

    private function gunOnce(string $tarih, int $gun): string
    {
        return Carbon::parse($tarih, LocalDay::timezone())->subDays($gun)->toDateString();
    }

    private function gunSonra(string $tarih, int $gun): string
    {
        return Carbon::parse($tarih, LocalDay::timezone())->addDays($gun)->toDateString();
    }
}
