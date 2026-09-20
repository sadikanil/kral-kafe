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
        $esik = (int) config('kafe.sayilabilir_dakika');
        $gunler = [];

        $gun = Carbon::parse($from, LocalDay::timezone());
        $bitis = Carbon::parse($to, LocalDay::timezone());

        while ($gun->lessThanOrEqualTo($bitis)) {
            $tarih = $gun->toDateString();

            if ($this->minutesOnDay($student, $tarih) >= $esik) {
                $gunler[] = $tarih;
            }

            $gun->addDay();
        }

        return $gunler;
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
            ->where('started_at', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from);
            })
            ->get()
            ->filter(fn (StudySession $o) => $o->minutesSoFar() >= $esik);
    }
}
