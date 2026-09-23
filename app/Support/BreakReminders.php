<?php

namespace App\Support;

/**
 * Esneme hatirlaticilari (Dalga 24). Karar (23 Eyl): "30 dk mikro + 60 dk
 * kalk", iki saatte bir gercek mola onerisi.
 *
 * Dayanak:
 *  - Oturmayi 20-30 dk'da bir 2-3 dk hafif hareketle bolmek yorgunlugu
 *    azaltiyor (Wu vd. 2023, Scand J Med Sci Sports; PMC5511092).
 *  - 20-20-20: 20 dk'da bir 20 sn uzaga bak (AOA). Ders calisirken 20 dk
 *    cok sik bolunme; 30 dk'lik mikro molaya katildi.
 *  - Mikro molalar zindeligi artirip yorgunlugu azaltiyor; agir zihinsel
 *    isten toparlanmak 10 dk'dan uzun mola isteyebilir (Albulescu vd. 2022,
 *    PLOS ONE) -> 2 saatte bir 15 dk mola onerisi.
 *
 * Sayac ARALIKSIZ calismaya bakar: her mola/duraklama onu sifirlar.
 * Mantik burada (test edilebilir); sayfadaki JS yalnizca zamani gelince
 * gosterir.
 */
final class BreakReminders
{
    private const ADIM_DAKIKA = 30;
    private const SON_DAKIKA = 8 * 60;

    /** @return array<int,array{at:int,kind:string,title:string,text:string}> */
    public static function schedule(): array
    {
        $liste = [];

        for ($dk = self::ADIM_DAKIKA; $dk <= self::SON_DAKIKA; $dk += self::ADIM_DAKIKA) {
            $tur = match (true) {
                $dk % 120 === 0 => 'mola',
                $dk % 60 === 0 => 'kalk',
                default => 'mikro',
            };

            $liste[] = ['at' => $dk * 60, 'kind' => $tur] + self::mesaj($tur, $dk);
        }

        return $liste;
    }

    /** @return array{title:string,text:string} */
    private static function mesaj(string $tur, int $dk): array
    {
        return match ($tur) {
            'mikro' => [
                'title' => 'Küçük bir nefes 👀',
                'text' => '20 saniye uzağa bak, omuzlarını ve boynunu esnet. Sonra devam.',
            ],
            'kalk' => [
                'title' => 'Kalk ve esne 🧍',
                'text' => "{$dk} dakikadır oturuyorsun. 3-5 dakika yürü, bacaklarını esnet, su iç.",
            ],
            'mola' => [
                'title' => 'Mola zamanı ☕',
                'text' => ($dk / 60) . ' saattir aralıksız çalışıyorsun. 15 dakikalık mola beynini toparlar.',
            ],
        };
    }
}
