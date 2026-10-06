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

    /** Bir urun kritik stok sayisina indi; yoneticiye (Dalga 29). */
    case LowStock = 'low_stock';

    /** Kurum denemesinin sonucu yayinlandi; ogrenciye, velisine, kocuna (1 Ekim 2026). */
    case ExamResult = 'exam_result';

    /** Kurum PDF'inin okumasi bitti ya da durdu; yoneticiye (6 Ekim 2026). */
    case ExamImport = 'exam_import';

    public function label(): string
    {
        return match ($this) {
            self::Absence => 'Devamsızlık',
            self::ExamTomorrow => 'Yarın deneme var',
            self::StockCount => 'Stok sayımı',
            self::LowStock => 'Kritik stok',
            self::ExamResult => 'Deneme sonucu',
            self::ExamImport => 'Deneme okuma',
        };
    }
}
