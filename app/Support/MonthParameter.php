<?php

namespace App\Support;

/**
 * Adres cubugundaki ?ay=YYYY-MM degerini yil/ay'a cevirir.
 *
 * Deger kullanicinin elinde: ?ay[]=2026-08 bir DIZI getirir ve
 * ExamCalendar::parseMonth(?string) daha govdesine girmeden TypeError
 * atiyordu - Odemeler ve Deneme Takvimi 500 veriyordu. Metin olmayan her
 * deger bozuk sayilir ve kafe ayina duser (?hafta icin WeekParameter ile
 * ayni kural). Ayi okuyan her kontrolcu buradan gecmeli.
 */
final class MonthParameter
{
    /** @return array{0:int,1:int} */
    public static function resolve(mixed $value): array
    {
        return ExamCalendar::parseMonth(is_string($value) ? $value : null);
    }
}
