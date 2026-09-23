<?php

namespace App\Enums;

/** Dalga 30b: ogrencinin sinifi. Hazirlik sinifi YKS icerigi tasimadigi icin yok. */
enum Grade: string
{
    case Nine = '9';
    case Ten = '10';
    case Eleven = '11';
    case Twelve = '12';
    case Graduate = 'mezun';

    public function label(): string
    {
        return $this === self::Graduate ? 'Mezun' : $this->value . '. sınıf';
    }

    /** Alan secimi 11. siniftan itibaren (MEB haftalik ders cizelgesi 2025). */
    public function hasField(): bool
    {
        return in_array($this, [self::Eleven, self::Twelve, self::Graduate], true);
    }
}
