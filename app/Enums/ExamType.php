<?php

namespace App\Enums;

/**
 * Deneme turu. Veritabaninda duz string; dogrulama Rule::enum ile.
 */
enum ExamType: string
{
    case Tyt = 'tyt';
    case Ayt = 'ayt';
    case TytAyt = 'tyt_ayt';
    case Lgs = 'lgs';
    /**
     * Resmi sinav (YKS, LGS tarihi) - bir DENEME DEGIL, hedefin kendisi.
     *
     * Ayri tablo ya da bayrak gerekmedi (SS7-G): bu tur yetiyor.
     */
    case Official = 'official';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Tyt => 'TYT',
            self::Ayt => 'AYT',
            self::TytAyt => 'TYT + AYT',
            self::Lgs => 'LGS',
            self::Official => 'Resmî Sınav',
            self::Other => 'Diğer',
        };
    }

    /**
     * Bu tur bir deneme mi?
     *
     * Resmi sinav "siradaki deneme" hatirlaticisina girmez: kutu
     * "Sıradaki deneme: YKS" derdi ve ogrencinin hafta sonu cozecegi
     * denemeyle girecegi sinavi ayni kefeye koyardi.
     */
    public function isPractice(): bool
    {
        return $this !== self::Official;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Tyt => 'info',
            self::Ayt => 'primary',
            self::TytAyt => 'warning',
            self::Lgs => 'success',
            self::Official => 'danger',
            self::Other => 'danger',
        };
    }
}
