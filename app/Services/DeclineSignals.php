<?php

namespace App\Services;

use App\Models\StudyGoal;
use App\Models\StudySession;
use App\Models\User;
use App\Support\DeclineSignal;
use App\Support\LocalDay;
use App\Support\WeekParameter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Devamlilik dusus sinyalleri (Dalga 16).
 *
 * Kural tabanli, YORUMSUZ. Esikler config/kafe.php'de.
 *
 * ONCE KOCA GIDER (SS6.1-5): veli ve ogrenci panellerinde gorunmez. Ham bir
 * dusus sinyali veliye dogrudan gitseydi, koc daha bakmadan evde tartisma
 * baslardi; veliye giden sey kocun YORUMU (Dalga 15a raporu).
 *
 * Dalga 11'in gun sonu devamsizlik bildiriminden farki: o TEK GUNE bakar ve
 * veliye gider, bu EGILIME bakar ve kocta kalir.
 *
 * Tablo yok - sinyal saklanmaz. Saklamak "ne zaman duzeldi" sorusunu da
 * yonetmeyi gerektirirdi.
 *
 * Ogrenci sayisindan BAGIMSIZ olarak uc sorgu calisir (oturumlar, son
 * gelisler, hedefler); koc listesi ogrenci basina sorgu acamaz (SS10.12).
 */
class DeclineSignals
{
    public function __construct(private StudyStats $istatistik)
    {
    }

    /**
     * @return list<DeclineSignal>
     */
    public function for(User $student): array
    {
        return $this->forStudents(collect([$student]))[$student->id] ?? [];
    }

    /**
     * @param  Collection<int,User>  $students
     * @return array<int,list<DeclineSignal>>
     */
    public function forStudents(Collection $students): array
    {
        $ids = $students->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        $bugun = LocalDay::today();
        $sonYediBas = $this->gunOnce($bugun, 6);
        $oncekiYediBas = $this->gunOnce($bugun, 13);
        $oncekiYediSon = $this->gunOnce($bugun, 7);
        $hedefHaftasi = WeekParameter::lastFinished();

        // Pencere hem 14 gunluk egilimi hem tamamlanmis haftayi kapsamali;
        // hangisi daha geriye gidiyorsa o baslangic.
        $pencereBas = min($oncekiYediBas, $hedefHaftasi);

        $gunluk = $this->istatistik->dailyMinutesForMany($ids, $pencereBas, $bugun);
        $sonGelisler = $this->lastVisits($ids);
        $hedefler = $this->goals($ids, $hedefHaftasi);

        $sonuc = [];

        foreach ($students as $ogrenci) {
            $id = (int) $ogrenci->id;
            $gunler = $gunluk[$id] ?? [];

            $sonuc[$id] = array_values(array_filter([
                $this->attendanceSignal($gunler, $sonYediBas, $bugun, $oncekiYediBas, $oncekiYediSon),
                $this->absenceSignal($sonGelisler[$id] ?? null, $bugun),
                $this->goalSignal($gunler, $hedefler[$id] ?? null, $hedefHaftasi),
            ]));
        }

        return $sonuc;
    }

    /**
     * Son 7 gun, onceki 7 gunden belirgin olarak az mi?
     *
     * 1 gunluk fark bilerek yetmiyor: kucuk dalgalanma sinyal sayilirsa liste
     * gurultuye doner ve koc ona bakmayi birakir - ozellik olur.
     */
    private function attendanceSignal(array $gunler, string $sonBas, string $sonSon, string $oncekiBas, string $oncekiSon): ?DeclineSignal
    {
        $son = $this->gelisSayisi($gunler, $sonBas, $sonSon);
        $onceki = $this->gelisSayisi($gunler, $oncekiBas, $oncekiSon);

        if ($onceki - $son < (int) config('kafe.dusus_gelis_farki')) {
            return null;
        }

        return new DeclineSignal(
            'attendance',
            "Son 7 günde {$son} geliş, önceki 7 günde {$onceki}",
            'warning',
        );
    }

    /**
     * Son gelisin uzerinden kac gun gecti?
     *
     * HIC GELMEMIS ogrenci icin sinyal YOK: yeni kayit olmus ogrenci
     * "dususte" degil, henuz baslamamis.
     */
    private function absenceSignal(?string $sonGelis, string $bugun): ?DeclineSignal
    {
        if ($sonGelis === null) {
            return null;
        }

        $gun = (int) Carbon::parse($sonGelis, LocalDay::timezone())
            ->diffInDays(Carbon::parse($bugun, LocalDay::timezone()));

        if ($gun < (int) config('kafe.dusus_devamsiz_gun')) {
            return null;
        }

        return new DeclineSignal('absence', "{$gun} gündür gelmedi", 'danger');
    }

    /**
     * Tamamlanmis son haftada hedefin ne kadari tuttu?
     *
     * SUREN haftaya bakilsaydi her ogrenci pazartesi sabahi isaretlenirdi -
     * haftalik raporun ayni karari (Dalga 15a).
     */
    private function goalSignal(array $gunler, ?StudyGoal $hedef, string $hafta): ?DeclineSignal
    {
        if ($hedef === null || (int) $hedef->target_minutes < 1) {
            return null;
        }

        $dakika = $this->araliktakiDakika($gunler, $hafta, $this->gunSonra($hafta, 6));
        $oran = (int) round($dakika / (int) $hedef->target_minutes * 100);

        if ($oran >= (int) config('kafe.dusus_hedef_orani')) {
            return null;
        }

        return new DeclineSignal('goal', "Geçen hafta hedefin %{$oran}'i", 'warning');
    }

    /** @return array<int,string> ogrenci -> son gelis gunu (Y-m-d) */
    private function lastVisits(array $ids): array
    {
        return StudySession::whereIn('student_id', $ids)
            ->countable()
            ->get(['student_id', 'ended_at'])
            ->groupBy('student_id')
            ->map(fn (Collection $satirlar) => LocalDay::of(
                $satirlar->max(fn (StudySession $o) => $o->ended_at)
            ))
            ->mapWithKeys(fn (string $gun, $id) => [(int) $id => $gun])
            ->all();
    }

    /**
     * O hafta yururlukte olan haftalik hedefler - TEK sorgu.
     *
     * @return array<int,StudyGoal>
     */
    private function goals(array $ids, string $hafta): array
    {
        return StudyGoal::whereIn('student_id', $ids)
            ->where('period', 'weekly')
            ->whereDate('effective_from', '<=', $hafta)
            ->where(function ($q) use ($hafta) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $hafta);
            })
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('student_id')
            ->mapWithKeys(fn (Collection $satirlar, $id) => [(int) $id => $satirlar->first()])
            ->all();
    }

    private function gelisSayisi(array $gunler, string $bas, string $son): int
    {
        $esik = (int) config('kafe.sayilabilir_dakika');
        $sayi = 0;

        foreach ($gunler as $gun => $dakika) {
            if ($gun >= $bas && $gun <= $son && $dakika >= $esik) {
                $sayi++;
            }
        }

        return $sayi;
    }

    private function araliktakiDakika(array $gunler, string $bas, string $son): int
    {
        $toplam = 0;

        foreach ($gunler as $gun => $dakika) {
            if ($gun >= $bas && $gun <= $son) {
                $toplam += $dakika;
            }
        }

        return $toplam;
    }

    private function gunOnce(string $gun, int $adet): string
    {
        return Carbon::parse($gun, LocalDay::timezone())->subDays($adet)->toDateString();
    }

    private function gunSonra(string $gun, int $adet): string
    {
        return Carbon::parse($gun, LocalDay::timezone())->addDays($adet)->toDateString();
    }
}
