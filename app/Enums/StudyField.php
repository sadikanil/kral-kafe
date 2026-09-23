<?php

namespace App\Enums;

/** Dalga 30b: YKS alani (puan turu). 9-10. sinifta alan yok. */
enum StudyField: string
{
    case Numerical = 'say';
    case EqualWeight = 'ea';
    case Verbal = 'soz';
    case Language = 'dil';

    public function label(): string
    {
        return match ($this) {
            self::Numerical => 'Sayısal',
            self::EqualWeight => 'Eşit Ağırlık',
            self::Verbal => 'Sözel',
            self::Language => 'Dil',
        };
    }
}
