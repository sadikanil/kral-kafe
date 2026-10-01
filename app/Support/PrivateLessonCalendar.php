<?php

namespace App\Support;

use App\Models\PrivateLessonSlot;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ozel ders takvimi (Dalga 25): haftalik saatlerden verilen araliktaki
 * dersleri uretir, istisnalari (iptal / tasima) uygular.
 *
 * Tasinan ders YENI gun ve saatinde, 'moved' olarak gorunur; iptal edilen
 * ders listeden dusmez, 'cancelled' olarak kalir - ogrenci "bu hafta ders
 * yok muydu, ben mi unuttum" diye sormasin.
 */
final class PrivateLessonCalendar
{
    /**
     * @return array<int,array{date:string,starts_at:string,ends_at:string,status:string,slot:PrivateLessonSlot,original_date:string}>
     */
    public static function between(User $student, string $from, string $to): array
    {
        return self::fromSlots(
            PrivateLessonSlot::with(['exceptions', 'teacher'])->where('student_id', $student->id)->get(),
            $from, $to,
        );
    }

    /**
     * Bir ogretmenin (koc/yonetici) verdigi dersler, tum ogrencileriyle
     * (1 Ekim 2026). Koc "Ozel Derslerim"de yalnizca kendi derslerini gorur.
     *
     * @return array<int,array{date:string,starts_at:string,ends_at:string,status:string,slot:PrivateLessonSlot,original_date:string}>
     */
    public static function forTeacher(User $teacher, string $from, string $to): array
    {
        return self::fromSlots(
            PrivateLessonSlot::with(['exceptions', 'student', 'teacher'])->where('teacher_id', $teacher->id)->get(),
            $from, $to,
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int,PrivateLessonSlot>  $saatler
     * @return array<int,array{date:string,starts_at:string,ends_at:string,status:string,slot:PrivateLessonSlot,original_date:string}>
     */
    private static function fromSlots($saatler, string $from, string $to): array
    {
        $bas = Carbon::parse($from)->startOfDay();
        $bit = Carbon::parse($to)->startOfDay();
        $dersler = [];

        foreach ($saatler as $saat) {
            $istisnalar = $saat->exceptions->keyBy(fn ($e) => $e->date->toDateString());

            // Tasima araliga GIREN dersleri de yakalamak icin aralik bir hafta
            // genisletilerek taranir, sonuc asagida yeniden suzulur.
            for ($gun = $bas->copy()->subWeek(); $gun->lessThanOrEqualTo($bit->copy()->addWeek()); $gun->addDay()) {
                if ($gun->dayOfWeekIso !== $saat->weekday
                    || $gun->lessThan($saat->starts_on)
                    || ($saat->ends_on && $gun->greaterThan($saat->ends_on))) {
                    continue;
                }

                $tarih = $gun->toDateString();
                $istisna = $istisnalar->get($tarih);

                $ders = match (true) {
                    $istisna === null => [$tarih, $saat->starts_at, $saat->ends_at, 'scheduled'],
                    $istisna->cancelled => [$tarih, $saat->starts_at, $saat->ends_at, 'cancelled'],
                    default => [$istisna->new_date->toDateString(), $istisna->new_starts_at, $istisna->new_ends_at, 'moved'],
                };

                if ($ders[0] < $bas->toDateString() || $ders[0] > $bit->toDateString()) {
                    continue;
                }

                $dersler[] = [
                    'date' => $ders[0], 'starts_at' => $ders[1], 'ends_at' => $ders[2],
                    'status' => $ders[3], 'slot' => $saat, 'original_date' => $tarih,
                ];
            }
        }

        usort($dersler, fn ($a, $b) => [$a['date'], $a['starts_at']] <=> [$b['date'], $b['starts_at']]);

        return $dersler;
    }
}
