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

        // Yalnizca bayat OLABILECEK adaylar okunur (QA perf P5): bu her
        // istekte calisiyor ve eskiden butun acik oturumlari (32 masaya kadar)
        // cekip PHP'de eliyordu. Suzgec isStale() ile birebir ayni:
        //   kapanis gecti  <=> baslangic, simdiye kadarki son kapanistan ONCE
        //   azami doldu    <=> baslangic <= simdi - azami saat
        // Sinirlar PHP'de hesaplanir; SQL'de saat dilimi aritmetigi yok, iki
        // surucude ayni sorgu. Normal bir istekte sonuc bos doner.
        $adaylar = StudySession::open()
            ->where(function ($q) use ($now) {
                $q->where('started_at', '<', $this->lastClosingUpTo($now)->utc())
                    ->orWhere('started_at', '<=', $now->copy()->subHours((int) config('kafe.azami_saat'))->utc());
            })
            ->get();

        foreach ($adaylar as $oturum) {
            if (! $this->isStale($oturum, $now)) {
                continue;
            }

            // Korumali yazma: get() ile save() arasinda ogrenci elle
            // bitirmis olabilir; kosulsuz UPDATE onun kapanisini ezerdi.
            if ($oturum->closeOnce($this->dueEnd($oturum), $this->reasonFor($oturum))) {
                $sayac++;
            }
        }

        return $sayac;
    }

    /**
     * Kafe bu anda acik mi? Yerel saatle [acilis, kapanis).
     *
     * Oturum yalnizca acikken baslar (QA hata 7): kapanistan sonra baslayan
     * oturumun kapanisi ertesi gunun 21:00'ine kayiyor, 12 saat siniri onu
     * ertesi SABAH kapatiyordu. Ogrenci sabah dunku oturuma devam ediyor ve
     * mesai ortasinda atiliyordu. Tam 21:00 da kapali sayilir.
     */
    public function isOpenAt(Carbon $moment): bool
    {
        $yerel = $moment->copy()->setTimezone(config('kafe.timezone'));

        return $yerel->greaterThanOrEqualTo($this->localTime($yerel, config('kafe.acilis')))
            && $yerel->lessThan($this->localTime($yerel, config('kafe.kapanis')));
    }

    /**
     * Verilen andan SONRAKI ilk kafe kapanisi.
     *
     * Hesap yerel saatte yapilir: sutunlar UTC ama kapanis "Istanbul'da 21:00"
     * demek. Tam 21:00'de baslayan oturum ertesi gunun kapanisina gider -
     * kafe o anda kapaniyor, sifir dakikalik oturum uretmek anlamsiz olurdu.
     * Uygulama artik kapaliyken oturum acmiyor (isOpenAt); bu dal eski ve
     * elle girilmis satirlar icin duruyor.
     */
    private function closingAfter(Carbon $moment): Carbon
    {
        $yerel = $moment->copy()->setTimezone(config('kafe.timezone'));

        $kapanis = $this->localTime($yerel, config('kafe.kapanis'));

        if ($kapanis->lessThanOrEqualTo($yerel)) {
            $kapanis->addDay();
        }

        return $kapanis;
    }

    /**
     * Verilen ana kadar (o an DAHIL) gerceklesmis son kafe kapanisi.
     *
     * closingAfter()'in tersi: closingAfter(baslangic) <= simdi ancak ve
     * ancak baslangic < lastClosingUpTo(simdi).
     */
    private function lastClosingUpTo(Carbon $moment): Carbon
    {
        $yerel = $moment->copy()->setTimezone(config('kafe.timezone'));
        $kapanis = $this->localTime($yerel, config('kafe.kapanis'));

        if ($kapanis->greaterThan($yerel)) {
            $kapanis->subDay();
        }

        return $kapanis;
    }

    /** Yerel gunun "SS:DD" saatindeki ani (config'teki acilis/kapanis). */
    private function localTime(Carbon $yerelGun, string $saatDakika): Carbon
    {
        [$saat, $dakika] = array_map('intval', explode(':', $saatDakika));

        return $yerelGun->copy()->setTime($saat, $dakika, 0);
    }

    private function limitAfter(Carbon $moment): Carbon
    {
        return $moment->copy()->addHours((int) config('kafe.azami_saat'));
    }
}
