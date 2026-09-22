<?php

namespace App\Enums;

use App\Support\LocalDay;
use Illuminate\Support\Carbon;

/**
 * study_plan_items.period - plan maddesinin donemi (Dalga 14).
 *
 * Dalga 13'te plan yalnizca haftalikti. Koc aylik hedef de verebilsin diye
 * donem bir boyut oldu. "Hangi tarih hangi kovaya duser" sorusunun tek
 * cevap yeri burasi; sutun adi tarihsel sebeple hala week_start
 * (bkz. README SS10.11).
 */
enum PlanPeriod: string
{
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Week => 'Haftalık',
            self::Month => 'Aylık',
        };
    }

    /**
     * Bu tarihin icinde bulundugu donemin baslangici (Y-m-d).
     *
     * Iki donem ay sinirinda AYRISIR: 1 Ekim persembe, o haftanin
     * pazartesisi 28 Eylul. Ayni gun icin haftalik plan eylule, aylik plan
     * ekime gider - tek sutunda tutmanin calismasi bu ayrima bagli.
     */
    public function startFor(string $date): string
    {
        return match ($this) {
            self::Week => LocalDay::weekStart($date),
            self::Month => LocalDay::monthStart($date),
        };
    }

    /**
     * Adres cubugundaki ?donem= degerini okur.
     *
     * Taninmayan deger 500 vermez, haftaliga duser: burasi bir raporlama
     * ekrani, girdi dogrulama kapisi degil. Yazma uclarinda dogrulama
     * Rule::enum() ile ayrica yapilir.
     */
    public static function fromRequest(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom($value) ?? self::Week)
            : self::Week;
    }

    /**
     * Adres cubugundaki ?baslangic= degerinden donem baslangicini cozer.
     *
     * startFor() ham Carbon::parse cagirir ve cop bir degerde firlatir;
     * burasi kullanicinin elindeki bir parametreyi okudugu icin toleransli:
     * taninmayan tarih bugune duser. Yazma ucunda dogrulama ayrica
     * yapiliyor, orada tolerans YOK.
     */
    public function startForRequest(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $this->startFor(LocalDay::today());
        }

        try {
            $gun = Carbon::parse($value, LocalDay::timezone())->toDateString();
        } catch (\Throwable) {
            return $this->startFor(LocalDay::today());
        }

        return $this->startFor($gun);
    }

    /**
     * Donemin ekranda gorunen adi.
     */
    public function titleFor(string $start): string
    {
        $bas = Carbon::parse($start, LocalDay::timezone())->locale('tr');

        if ($this === self::Month) {
            return $bas->translatedFormat('F Y');
        }

        $son = $bas->copy()->addDays(6);

        // Iki aya yayilan haftada ay adi iki kez gecer; ayni aydaysa bir kez.
        return $bas->month === $son->month
            ? $bas->translatedFormat('j') . ' - ' . $son->translatedFormat('j F Y')
            : $bas->translatedFormat('j F') . ' - ' . $son->translatedFormat('j F Y');
    }

    /**
     * Donemi ileri/geri kaydirir - koc gecmise ve gelecege bakabilsin diye.
     *
     * Ayda addMonthsNoOverflow: gun tasmasi ay basinda zararsiz gorunse de
     * (her zaman ayin 1'i), baslangicin baska bir gune kaydigi bir cagri
     * sessizce subati mart yapardi.
     */
    public function shift(string $start, int $step): string
    {
        $an = Carbon::parse($start, LocalDay::timezone());

        return match ($this) {
            self::Week => $an->addWeeks($step)->toDateString(),
            self::Month => $an->startOfMonth()->addMonthsNoOverflow($step)->toDateString(),
        };
    }
}
