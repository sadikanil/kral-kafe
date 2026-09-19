<?php

namespace App\Services;

use App\Enums\SessionEndReason;
use App\Models\StudySession;
use Illuminate\Support\Carbon;

/**
 * Unutulan calisma oturumlarini kapatir.
 *
 * TASARIMIN TEK KRITIK OZELLIGI: bitis ani, isin ne zaman calistigina degil,
 * oturumun kendi verisine bagli -> min(kapanis ani, baslangic + azami saat).
 *
 * Bunun uc sonucu var:
 *   1. Idempotan. Iki kez calistirmak ikinci kez hicbir sey degistirmez.
 *   2. Cron sapmasi onemsiz. Vercel Hobby cron'u +-59 dk kayabiliyor; bitis
 *      ani degismedigi icin sure yine dogru.
 *   3. Cron HIC calismasa bile sistem dogru. Bakan ilk kisi (middleware)
 *      hesabi ayni sonuca varir. Cron yalnizca "kimse bakmasa da veri taze
 *      olsun" icindir.
 *
 * ended_at = now() olsaydi ucu de bozulurdu: ogrencinin suresi, isin ne zaman
 * calistigina gore uzardi.
 *
 * Neden tek bir toplu UPDATE degil: her satirin bitis ani kendi started_at'ine
 * bagli ve "yerel saatle bir sonraki 21:00" ifadesi SQLite ile Postgres'te
 * bambaska yazilir. PHP tarafinda satir satir hesaplamak iki surucude de ayni
 * sonucu verir; acik oturum sayisi kafe kapasitesiyle sinirli.
 */
class SessionCloser
{
    /**
     * Bu oturumun kapanmasi GEREKEN an. UTC dondurur.
     *
     * UTC olmasi SART: Eloquent bir Carbon yazarken onu UTC ye CEVIRMEZ,
     * kendi saat diliminin duvar saatini bicimleyip saklar. Istanbul saatiyle
     * 21:00 tasiyan bir Carbon veritabanina "21:00" diye yazilir ve geri
     * okundugunda UTC 21:00 (yerel 00:00) olur - uc saatlik sessiz kayma.
     */
    public function dueEnd(StudySession $session): Carbon
    {
        $kapanis = $this->closingAfter($session->started_at);
        $azami = $this->limitAfter($session->started_at);

        return ($kapanis->lessThanOrEqualTo($azami) ? $kapanis : $azami)->copy()->utc();
    }

    /**
     * Azami sure kapanistan ONCE dolduysa bu bir anomalidir: ogrenci 12 saatten
     * uzun sure "masada" gorunmus demektir.
     */
    public function reasonFor(StudySession $session): SessionEndReason
    {
        return $this->limitAfter($session->started_at)
            ->lessThan($this->closingAfter($session->started_at))
                ? SessionEndReason::OverLimit
                : SessionEndReason::AutoClosed;
    }

    public function isStale(StudySession $session, ?Carbon $now = null): bool
    {
        return $this->dueEnd($session)->lessThanOrEqualTo($now ?? now());
    }

    /**
     * Bayat oturumlari kapatir, kapatilan sayisini dondurur.
     */
    public function closeStale(?Carbon $now = null): int
    {
        $now ??= now();
        $sayac = 0;

        foreach (StudySession::open()->get() as $oturum) {
            if (! $this->isStale($oturum, $now)) {
                continue;
            }

            $bitis = $this->dueEnd($oturum);

            $oturum->forceFill([
                'ended_at' => $bitis,
                'duration_minutes' => $oturum->minutesSoFar($bitis),
                'end_reason' => $this->reasonFor($oturum),
            ])->save();

            $sayac++;
        }

        return $sayac;
    }

    /**
     * Verilen andan SONRAKI ilk kafe kapanisi.
     *
     * Hesap yerel saatte yapilir: sutunlar UTC ama kapanis "Istanbul'da 21:00"
     * demek. Tam 21:00'de baslayan oturum ertesi gunun kapanisina gider -
     * kafe o anda kapaniyor, sifir dakikalik oturum uretmek anlamsiz olurdu.
     */
    private function closingAfter(Carbon $moment): Carbon
    {
        $yerel = $moment->copy()->setTimezone(config('kafe.timezone'));

        [$saat, $dakika] = array_map('intval', explode(':', config('kafe.kapanis')));

        $kapanis = $yerel->copy()->setTime($saat, $dakika, 0);

        if ($kapanis->lessThanOrEqualTo($yerel)) {
            $kapanis->addDay();
        }

        return $kapanis;
    }

    private function limitAfter(Carbon $moment): Carbon
    {
        return $moment->copy()->addHours((int) config('kafe.azami_saat'));
    }
}
