<?php

namespace App\Enums;

/**
 * coach_notes.kind - notun turu (Dalga 14b).
 *
 * Gorusme kaydi AYRI TABLO DEGIL (SS7-I): tek yonlu bir kayit ve duz notla
 * ayni alanlari tasiyor. Ayri tablo, ayni sorgunun iki kez yazilmasi ve
 * ekranda iki listenin birlestirilmesi demekti.
 */
enum CoachNoteKind: string
{
    case Note = 'note';
    case Meeting = 'meeting';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Not',
            self::Meeting => 'Veli görüşmesi',
        };
    }

    /**
     * Bu tur "ne zaman oldu" bilgisi ister mi?
     *
     * Gorusme cogu zaman sonradan yaziliyor; created_at'i gorusme ani saymak
     * "ne zaman konusuldu" sorusunu gunler kaydirirdi. Duz notta boyle bir
     * ayrim yok - yazildigi an olayin kendisi.
     */
    public function needsDate(): bool
    {
        return $this === self::Meeting;
    }
}
