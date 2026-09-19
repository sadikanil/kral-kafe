<?php

namespace App\Support;

/**
 * QR gorseli ureten tek yer.
 *
 * Adres daha once uc blade'de ayri ayri elle kuruluydu (lokasyon QR ekrani,
 * yazdirma sayfasi, lokasyon detayi). Masalar da QR alinca bes kopya olacakti;
 * saglayici degistiginde ya da adres formati bozuldugunda hangi ekranin
 * guncellendigi takip edilemez hale gelirdi.
 *
 * Eskiden bu isi App\Services\QRCodeService yapiyordu ama HICBIR yerden
 * cagrilmiyordu: uc blade servisi atlayip adresi kendi kuruyordu. Servis ayrica
 * Location'a bagliydi ve istek aninda file_get_contents ile dis sunucudan
 * indirme yapiyordu. Silindi; yerine bu saf yardimci geldi.
 *
 * Not: gorsel dis bir servisten (goqr.me) geliyor. Yazdirma ekrani internet
 * baglantisi gerektirir - etiketler basilirken bilinmesi gereken bir sey.
 */
class QrImage
{
    public static function url(string $data, int $size = 300): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/'
            . '?size=' . $size . 'x' . $size
            . '&data=' . urlencode($data)
            . '&format=png';
    }
}
