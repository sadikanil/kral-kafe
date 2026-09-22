<?php

namespace App\Enums;

/**
 * Bir calisma oturumunun yonetici onay durumu (Dalga 9).
 *
 * end_reason'dan AYRI bir sutun, ona eklenen bir deger degil: end_reason
 * oturumun NASIL bittigini soyler (ogrenci bitirdi / masa degisti / otomatik
 * kapandi / limit asildi), onay ise ondan bagimsiz bir eksen. Ikisini tek
 * sutunda birlestirmek "otomatik kapandi VE onaylandi" durumunu temsil
 * edilemez yapardi - Dalga 3'te switched/auto_closed icin verilen kararin
 * aynisi.
 */
enum ApprovalStatus: string
{
    /** Bitti, yonetici bakmadi. Ogrenci kendi suresini gorur, veli goremez. */
    case Pending = 'pending';

    /** Yonetici dogruladi. Istatistige giren tek durum. */
    case Approved = 'approved';

    /** Yonetici reddetti. Kayit SILINMEZ, sebebiyle birlikte ogrenciye gorunur. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Onay bekliyor',
            self::Approved => 'Onaylandı',
            self::Rejected => 'Reddedildi',
        };
    }
}
