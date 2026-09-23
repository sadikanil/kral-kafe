<?php

namespace App\Enums;

/** Dalga 30c: ogrencinin kafe disindaki haftalik sabit programi. */
enum CommitmentKind: string
{
    case School = 'okul';
    case Course = 'dershane';
    case Tutor = 'ozel_ders';
    case Other = 'diger';

    public function label(): string
    {
        return match ($this) {
            self::School => 'Okul',
            self::Course => 'Dershane',
            self::Tutor => 'Dış özel ders',
            self::Other => 'Diğer',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::School => '🏫',
            self::Course => '🏢',
            self::Tutor => '👤',
            self::Other => '📌',
        };
    }
}
