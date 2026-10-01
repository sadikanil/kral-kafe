<?php

namespace App\Enums;

/**
 * Bir calisma oturumunun NEDEN kapandigi.
 *
 * Tek sutun, iki boolean degil. 'switched' ile 'auto_closed' ayni anda olamaz;
 * iki ayri bayrak temsil edilemeyen durumlar uretir (ikisi de true, ikisi de
 * false) ve her okuma yerinde "hangisi oncelikli" karari tekrar verilir.
 */
enum SessionEndReason: string
{
    /** Ogrenci kendi bitirdi. */
    case Manual = 'manual';

    /** Baska masada QR okuttu; eski oturum kapandi. */
    case Switched = 'switched';

    /**
     * Kafe kapanis saatinde otomatik kapandi (Dalga 4). 1 Ekim 2026'dan beri
     * yeni kayit almiyor (21:00 kurali kalkti); eski kayitlar icin duruyor.
     */
    case AutoClosed = 'auto_closed';

    /**
     * Gun sonunda (yerel 00:00) acik kalan oturum kapandi (1 Ekim 2026).
     * Ogrenci cikisi bildirmemis ya da duraklatip gitmis demektir; onay
     * kuyrugunda etiketli gorunur ve toplu onaya girmez.
     */
    case DayEnd = 'day_end';

    /** Azami sureyi asti; anomali olarak yoneticiye dusuyor (Dalga 4). */
    case OverLimit = 'over_limit';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Öğrenci bitirdi',
            self::Switched => 'Masa değiştirdi',
            self::AutoClosed => 'Kapanışta otomatik',
            self::DayEnd => 'Gece 00:00\'da kapandı',
            self::OverLimit => 'Süre aşımı',
        };
    }

    /** Yoneticinin bakmasi gereken kapanislar. */
    public function isAnomaly(): bool
    {
        return $this === self::OverLimit;
    }

    /**
     * Yoneticinin TEK TEK bakmasi gereken kapanislar: sistem kapatti, ogrenci
     * degil. "Hepsini onayla" bunlari atlar; aksi halde duraklatip giden
     * ogrencinin suresi kalabalik bir kuyrukta fark edilmeden onaylanirdi.
     */
    public function needsExplicitReview(): bool
    {
        return in_array($this, self::explicitReview(), true);
    }

    /** @return list<self> */
    public static function explicitReview(): array
    {
        return [self::DayEnd, self::OverLimit];
    }
}
