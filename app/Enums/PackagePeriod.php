<?php

namespace App\Enums;

/** package_items.period - kapsam limitinin donemi. */
enum PackagePeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'günde',
            self::Weekly => 'haftada',
            self::Monthly => 'ayda',
        };
    }
}
