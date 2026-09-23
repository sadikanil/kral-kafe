<?php

namespace App\Support;

use App\Models\ExamEvent;
use App\Models\StudentCommitment;
use App\Models\StudyPlanItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bir ogrencinin haftasi, gun gun (Dalga 30c).
 *
 * Tek hesap, uc ekran (koc, ogrenci, veli): ayni takvim parcasi bunu
 * cizer. Her gunde dort sey var:
 *   commitments - okul, dershane, distaki ozel ders (haftalik sabit)
 *   lessons     - kafedeki ozel ders (Tier 3; iptal/tasima islenmis)
 *   exams       - TARIHLI denemeler (serbest olan ancak ogrenci bir gune
 *                 koyunca, plan maddesi olarak gorunur)
 *   items       - plan maddeleri; saatliler saate gore, saatsizler sonda
 */
final class WeekPlan
{
    /**
     * @return list<array{date:string,label:string,isToday:bool,commitments:Collection,lessons:array,exams:Collection,items:Collection}>
     */
    public static function for(User $student, string $anyDay): array
    {
        $bas = Carbon::parse(LocalDay::weekStart($anyDay), LocalDay::timezone());
        $son = $bas->copy()->addDays(6)->toDateString();
        $bugun = LocalDay::today();

        $maddeler = StudyPlanItem::where('student_id', $student->id)
            ->whereBetween('plan_date', [$bas->toDateString(), $son])
            ->with(['subject', 'topic', 'examEvent'])
            ->get()
            ->groupBy(fn (StudyPlanItem $m) => $m->plan_date->toDateString());

        $program = StudentCommitment::where('student_id', $student->id)
            ->orderBy('starts_at')
            ->get()
            ->groupBy('weekday');

        $denemeler = ExamEvent::whereBetween('exam_date', [$bas->toDateString(), $son])
            ->where('is_flexible', false)
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (ExamEvent $e) => $e->dateKey());

        $dersler = $student->entitlements()->privateLessons
            ? collect(PrivateLessonCalendar::between($student, $bas->toDateString(), $son))->groupBy('date')
            : collect();

        $gunler = [];
        for ($i = 0; $i < 7; $i++) {
            $gun = $bas->copy()->addDays($i);
            $tarih = $gun->toDateString();

            $gunler[] = [
                'date' => $tarih,
                'label' => $gun->locale('tr')->translatedFormat('D j'),
                'isToday' => $tarih === $bugun,
                'commitments' => $program->get($gun->isoWeekday(), collect()),
                'lessons' => $dersler->get($tarih, collect())->all(),
                'exams' => $denemeler->get($tarih, collect()),
                'items' => self::sirala($maddeler->get($tarih, collect())),
            ];
        }

        return $gunler;
    }

    /** Saatliler saate gore, saatsizler sonda; esitlikte eklenme sirasi. */
    private static function sirala(Collection $maddeler): Collection
    {
        return $maddeler
            ->sortBy(fn (StudyPlanItem $m) => [$m->starts_at === null ? 1 : 0, $m->starts_at ?? '', $m->id])
            ->values();
    }
}
