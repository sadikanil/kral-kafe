<?php

namespace App\Support;

use App\Enums\ExamType;
use App\Models\ExamEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Sonuc girerken serbest deneme (5 Ekim 2026).
 *
 * Serbest denemeler akis icinde, kendiliginden cozuluyor: yonetici sonucu
 * girerken denemeyi takvimde aramak ya da once takvime gidip eklemek
 * zorunda kalmasin. Sonuc formlari once serbest denemeleri (penceresi
 * kapanmis olanlar dahil) ayri grupta sunar; listede yoksa ayni formda
 * yenisi acilir. Yeni deneme tek gunluk pencereyle kaydedilir: cozuldugu
 * gun belli, ogrencinin "serbest denemeler" kutusunda sonradan durmaz.
 */
final class SpontaneousExam
{
    /** Formdaki secim degeri: "yeni deneme olustur". */
    public const NEW = 'yeni';

    /** Sonuc girilebilen turler (net ve konu hesabi TYT/AYT derslerine dayali). */
    public const TYPES = [ExamType::Tyt, ExamType::Ayt, ExamType::TytAyt];

    /**
     * Serbest denemeler, yeniden eskiye; pencere kapanmis olsa da.
     *
     * @param list<ExamType> $turler
     */
    public static function recent(array $turler = self::TYPES, int $limit = 40): Collection
    {
        return ExamEvent::where('is_flexible', true)
            ->whereIn('exam_type', array_column($turler, 'value'))
            ->orderByDesc('exam_date')->orderByDesc('id')
            ->limit($limit)->get();
    }

    public static function wantsNew(Request $request, string $alan = 'exam_event_id'): bool
    {
        return $request->input($alan) === self::NEW;
    }

    /**
     * new_* alanlarinin kurallari; yeni deneme istenmiyorsa hic dogrulanmaz.
     *
     * @return array<string,array>
     */
    public static function rules(bool $yeni): array
    {
        $atla = Rule::excludeIf(! $yeni);

        return [
            'new_title' => [$atla, 'required', 'string', 'max:100'],
            'new_exam_type' => [$atla, 'required', Rule::in(array_column(self::TYPES, 'value'))],
            // Cozulmus deneme: gelecek tarih bir yazim hatasidir.
            'new_exam_date' => [$atla, 'required', 'date_format:Y-m-d', 'before_or_equal:' . LocalDay::today()],
        ];
    }

    /** @return array<string,string> */
    public static function attributes(): array
    {
        return ['new_title' => 'deneme adı', 'new_exam_type' => 'sınav türü', 'new_exam_date' => 'çözüldüğü gün'];
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return ['new_exam_date.before_or_equal' => 'Çözüldüğü gün bugünden sonra olamaz.'];
    }

    /** @param array{new_title:string,new_exam_type:string,new_exam_date:string} $veri */
    public static function create(array $veri, int $olusturan): ExamEvent
    {
        return ExamEvent::create([
            'title' => $veri['new_title'],
            'exam_type' => $veri['new_exam_type'],
            'exam_date' => $veri['new_exam_date'],
            'available_until' => $veri['new_exam_date'],
            'is_flexible' => true,
            'created_by' => $olusturan,
        ]);
    }

    /** Secim kutusundaki etiket: "Serbest · 1–31 Ekim · Ad (TYT)". */
    public static function label(ExamEvent $deneme): string
    {
        $gun = $deneme->available_until && ! $deneme->available_until->isSameDay($deneme->exam_date)
            ? $deneme->windowLabel()
            : $deneme->exam_date->format('d.m.Y');

        return "{$gun} · {$deneme->title} ({$deneme->exam_type->label()})";
    }
}
