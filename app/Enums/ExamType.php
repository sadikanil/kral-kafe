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
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Tyt => 'TYT',
            self::Ayt => 'AYT',
            self::TytAyt => 'TYT + AYT',
            self::Lgs => 'LGS',
            self::Other => 'Diğer',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Tyt => 'info',
            self::Ayt => 'primary',
            self::TytAyt => 'warning',
            self::Lgs => 'success',
            self::Other => 'danger',
        };
    }
}
