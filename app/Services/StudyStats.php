<?php

namespace App\Services;

use App\Models\StudySession;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;

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
 */
class StudyStats
{
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
        $toplam = 0;

        foreach ($this->overlapping($student, $from, $to) as $oturum) {
            $bitis = $oturum->ended_at ?? now();

            // Kesisim: max(baslangiclar) .. min(bitisler)
            $kesisimBas = $oturum->started_at->greaterThan($from) ? $oturum->started_at : $from;
            $kesisimSon = $bitis->lessThan($to) ? $bitis : $to;

            if ($kesisimSon->greaterThan($kesisimBas)) {
                $toplam += (int) $kesisimBas->diffInMinutes($kesisimSon);
            }
        }

        return $toplam;
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

        foreach ($this->overlapping($student, $from, $to)->load('subject') as $oturum) {
            $bitis = $oturum->ended_at ?? now();

            $kesisimBas = $oturum->started_at->greaterThan($from) ? $oturum->started_at : $from;
            $kesisimSon = $bitis->lessThan($to) ? $bitis : $to;

            if (! $kesisimSon->greaterThan($kesisimBas)) {
                continue;
            }

            $ad = $oturum->subject?->name ?? 'Genel';
            $kova[$ad] = ($kova[$ad] ?? 0) + (int) $kesisimBas->diffInMinutes($kesisimSon);
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
        [$bas] = LocalDay::weekBounds(LocalDay::today());
        [$son] = LocalDay::weekBounds(
            Carbon::parse(LocalDay::today(), LocalDay::timezone())->addWeek()->toDateString()
        );

        return $this->minutesBetween($student, $bas, $son);
    }

    public function monthMinutes(User $student): int
    {
        $bugun = Carbon::parse(LocalDay::today(), LocalDay::timezone());
        $sonraki = $bugun->copy()->addMonthNoOverflow();

        [$bas] = LocalDay::monthBounds($bugun->year, $bugun->month);
        [$son] = LocalDay::monthBounds($sonraki->year, $sonraki->month);

        return $this->minutesBetween($student, $bas, $son);
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

        $esik = (int) config('kafe.sayilabilir_dakika');
        $tz = LocalDay::timezone();

        [$pencereBas] = LocalDay::bounds($from);
        [$pencereSon] = LocalDay::bounds(
            Carbon::parse($to, $tz)->addDay()->toDateString()
        );

        // Bos gunler de anahtar olarak dursun: cagiran taraf "o gun sifir"
        // ile "o gun hic yok" ayrimini yapmak zorunda kalmasin.
        $kova = [];
        foreach ($studentIds as $id) {
            $kova[(int) $id] = [];
        }

        $oturumlar = StudySession::whereIn('student_id', $studentIds)
            ->approved()
            ->where('started_at', '<=', $pencereSon)
            ->where(function ($q) use ($pencereBas) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>=', $pencereBas);
            })
            ->get()
            // Esikten kisa oturumlar burada elenir - gune bolunmus parcalari
            // degil, oturumun KENDI toplam suresi degerlendirilir.
            ->filter(fn (StudySession $o) => $o->minutesSoFar() >= $esik);

        foreach ($oturumlar as $oturum) {
            $bitis = $oturum->ended_at ?? now();
            $gun = Carbon::parse(LocalDay::of($oturum->started_at), $tz);
            $sonGun = Carbon::parse(LocalDay::of($bitis), $tz);

            while ($gun->lessThanOrEqualTo($sonGun)) {
                $tarih = $gun->toDateString();

                if ($tarih >= $from && $tarih <= $to) {
                    [$gunBas] = LocalDay::bounds($tarih);
                    [$gunSon] = LocalDay::bounds($gun->copy()->addDay()->toDateString());

                    $kesBas = $oturum->started_at->greaterThan($gunBas) ? $oturum->started_at : $gunBas;
                    $kesSon = $bitis->lessThan($gunSon) ? $bitis : $gunSon;

                    if ($kesSon->greaterThan($kesBas)) {
                        $ogrenciId = (int) $oturum->student_id;
                        $kova[$ogrenciId][$tarih] = ($kova[$ogrenciId][$tarih] ?? 0)
                            + (int) $kesBas->diffInMinutes($kesSon);
                    }
                }

                $gun->addDay();
            }
        }

        foreach ($kova as $id => $gunler) {
            ksort($gunler);
            $kova[$id] = $gunler;
        }

        return $kova;
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
        $esik = (int) config('kafe.sayilabilir_dakika');
        $gun = Carbon::parse(LocalDay::today(), LocalDay::timezone());

        if ($this->minutesOnDay($student, $gun->toDateString()) < $esik) {
            $gun->subDay();
        }

        $seri = 0;

        while ($seri < $maxDays && $this->minutesOnDay($student, $gun->toDateString()) >= $esik) {
            $seri++;
            $gun->subDay();
        }

        return $seri;
    }

    /**
     * Araligi kesen oturumlar.
     *
     * whereBetween sutuna fonksiyon uygulamadigi icin indeks kullanilabilir
     * kalir. Acik oturumlarin ended_at'i null oldugundan ayrica alinir.
     *
     * Esikten kisa oturumlar burada elenir - gune bolunmus parcalari degil,
     * oturumun KENDI toplam suresi degerlendirilir.
     */
    private function overlapping(User $student, Carbon $from, Carbon $to)
    {
        $esik = (int) config('kafe.sayilabilir_dakika');

        return StudySession::where('student_id', $student->id)
            ->approved()
            ->where('started_at', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from);
            })
            ->get()
            ->filter(fn (StudySession $o) => $o->minutesSoFar() >= $esik);
    }
}
