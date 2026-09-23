<?php

namespace App\Enums;

/** Dalga 23: calisma oturumundaki duraklama turu. */
enum PauseKind: string
{
    /** Ogrencinin serbest duraklatmasi; suresi yok. */
    case Pause = 'pause';

    /** Hizli eylem: 15 dk mola. */
    case Break = 'break';

    /** Hizli eylem: 1 saat ogle arasi. Kafeden cikmak serbest. */
    case Lunch = 'lunch';

    public function label(): string
    {
        return match ($this) {
            self::Pause => 'Duraklatıldı',
            self::Break => 'Mola',
            self::Lunch => 'Öğle arası',
        };
    }

    public function plannedMinutes(): ?int
    {
        return match ($this) {
            self::Pause => null,
            self::Break => 15,
            self::Lunch => 60,
        };
    }
}
