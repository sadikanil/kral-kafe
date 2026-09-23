<?php

namespace App\Enums;

/**
 * Bildirim turleri (Dalga 11).
 *
 * Tur string sutunda tutuluyor, enum degil - Postgres'te enum degistirmek
 * migration'i cokertiyor (bkz. README SS10.2).
 */
enum NotificationType: string
{
    /** Ogrenci o gun kafeye gelmedi; velisine. */
    case Absence = 'absence';

    /** Yarin deneme var; ogrenciye ve velisine. */
    case ExamTomorrow = 'exam_tomorrow';

    /** Stok sayimi zamani geldi; yoneticiye (Dalga 27). */
    case StockCount = 'stock_count';

    public function label(): string
    {
        return match ($this) {
            self::Absence => 'Devamsızlık',
            self::ExamTomorrow => 'Yarın deneme var',
            self::StockCount => 'Stok sayımı',
        };
    }
}
