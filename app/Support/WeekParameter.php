<?php

namespace App\Support;

use App\Enums\PlanPeriod;
use Illuminate\Support\Carbon;

/**
 * Adres cubugundaki ?hafta= degerini rapor haftasina cevirir (Dalga 15a).
 *
 * Deger kullanicinin elinde: taninmayan tarih 500 vermemeli. Uc ekran
 * (veli, ogrenci, koc) ayni kurali kullaniyor; ayri ayri yazilsaydi biri
 * gun gelip digerinden farkli bir haftayi acardi.
 */
class WeekParameter
{
    /**
     * Verilmezse TAMAMLANMIS SON hafta.
     *
     * Suren haftaya varsayilmak, raporu acan velinin her seferinde "henuz
     * hazir degil" gormesi demekti - ozellik kullanilmaz gorunurdu.
     */
    public static function resolve(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return self::lastFinished();
        }

        try {
            return LocalDay::weekStart(Carbon::parse($value, LocalDay::timezone())->toDateString());
        } catch (\Throwable) {
            return self::lastFinished();
        }
    }

    public static function lastFinished(): string
    {
        return PlanPeriod::Week->shift(LocalDay::weekStart(LocalDay::today()), -1);
    }
}
