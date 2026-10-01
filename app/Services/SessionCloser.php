<?php

namespace App\Services;

use App\Enums\SessionEndReason;
use App\Models\StudySession;
use Illuminate\Support\Carbon;

/**
 * Unutulan calisma oturumlarini kapatir.
 *
 * TASARIMIN TEK KRITIK OZELLIGI: bitis ani, isin ne zaman calistigina degil,
 * oturumun kendi verisine bagli -> min(gun sonu, baslangic + azami saat).
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
 * Kapanis saati kurali (21:00) 1 Ekim 2026'da KALDIRILDI: kafe kapanisi
 * esnek, 21:00'de masadaki ogrenciyi atmak yanlisti. Yerine GUN SONU (yerel
 * 00:00) geldi: aksam istenildigi kadar uzayabilir ama hicbir oturum ertesi
 * gune tasmaz (QA hata 7: gece boyu acik kalan oturum sabah dunku sayaci
 * gosteriyor, yeni masada yuzlerce dakikalik "masa degistirdi" kaydi
 * birakiyordu).
 *
 * Neden tek bir toplu UPDATE degil: her satirin bitis ani kendi started_at'ine
 * bagli ve closeOnce() yarisa karsi satir satir korumali yaziyor. Acik oturum
 * sayisi kafe kapasitesiyle sinirli.
 */
class SessionCloser
{
    /**
     * Bu oturumun kapanmasi GEREKEN an. UTC dondurur.
     *
     * UTC olmasi SART: Eloquent bir Carbon yazarken onu UTC ye CEVIRMEZ,
     * kendi saat diliminin duvar saatini bicimleyip saklar (README SS10.1).
     */
    public function dueEnd(StudySession $session): Carbon
    {
        $gunSonu = $this->dayEndAfter($session->started_at);
        $azami = $this->limitAfter($session->started_at);

        return ($gunSonu->lessThanOrEqualTo($azami) ? $gunSonu : $azami)->copy()->utc();
    }

    /**
     * Azami sure gun sonundan ONCE dolduysa bu bir anomalidir: ogrenci 12
     * saatten uzun sure "masada" gorunmus demektir.
     */
    public function reasonFor(StudySession $session): SessionEndReason
    {
        return $this->limitAfter($session->started_at)
            ->lessThan($this->dayEndAfter($session->started_at))
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
        // istekte calisiyor. Suzgec isStale() ile birebir ayni:
        //   gun bitti   <=> baslangic, bugunun yerel 00:00'indan ONCE
        //   azami doldu <=> baslangic <= simdi - azami saat
        // Sinirlar PHP'de hesaplanir; SQL'de saat dilimi aritmetigi yok, iki
        // surucude ayni sorgu. Normal bir istekte sonuc bos doner.
        $adaylar = StudySession::open()
            ->where(function ($q) use ($now) {
                $q->where('started_at', '<', $this->dayStartOf($now)->utc())
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
     * Bu anda oturum baslatilabilir mi? Yerel saatle acilistan (09:00) gun
     * sonuna (00:00) kadar. Sabit kapanis saati yok: aksam esnek.
     */
    public function isOpenAt(Carbon $moment): bool
    {
        $yerel = $moment->copy()->setTimezone(config('kafe.timezone'));
        [$saat, $dakika] = array_map('intval', explode(':', config('kafe.acilis')));

        return $yerel->greaterThanOrEqualTo($yerel->copy()->setTime($saat, $dakika, 0));
    }

    /** Verilen andan SONRAKI ilk yerel gece yarisi (gun sonu). */
    private function dayEndAfter(Carbon $moment): Carbon
    {
        return $this->dayStartOf($moment)->addDay();
    }

    /** Verilen anin yerel gununun 00:00'i. dayEndAfter(b) <= simdi <=> b < dayStartOf(simdi). */
    private function dayStartOf(Carbon $moment): Carbon
    {
        return $moment->copy()->setTimezone(config('kafe.timezone'))->startOfDay();
    }

    private function limitAfter(Carbon $moment): Carbon
    {
        return $moment->copy()->addHours((int) config('kafe.azami_saat'));
    }
}
