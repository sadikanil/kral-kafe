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

    /** Kafe kapanis saatinde otomatik kapandi (Dalga 4). */
    case AutoClosed = 'auto_closed';

    /** Azami sureyi asti; anomali olarak yoneticiye dusuyor (Dalga 4). */
    case OverLimit = 'over_limit';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Öğrenci bitirdi',
            self::Switched => 'Masa değiştirdi',
            self::AutoClosed => 'Kapanışta otomatik',
            self::OverLimit => 'Süre aşımı',
        };
    }

    /** Yoneticinin bakmasi gereken kapanislar. */
    public function isAnomaly(): bool
    {
        return $this === self::OverLimit;
    }
}
