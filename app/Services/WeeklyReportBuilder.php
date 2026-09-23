<?php

namespace App\Services;

use App\Enums\PlanPeriod;
use App\Models\ExamResult;
use App\Models\StudyGoal;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;

/**
 * Haftalik veli raporunun uretilmesi (Dalga 15a).
 *
 * TEMBEL URETIM: rapor ilk acilista hesaplanip saklaniyor, cron gerektirmiyor.
 * Bildirim tarafi cron'a bagli (SS7-K) ama raporun kendisi paneli acan ilk
 * kiside uretilebilir.
 *
 * Uretildikten sonra DEGISMEZ: velinin gordugu sayinin altindan kaymamasi
 * gerekiyor. Gecikmis onay gibi durumlar icin acik bir regenerate() var -
 * sessiz degisim yok, bilincli bir islem.
 */
class WeeklyReportBuilder
{
    public function __construct(private StudyStats $istatistik)
    {
    }

    /**
     * Haftanin raporu. Yoksa uretir; hafta bitmemisse null.
     */
    public function for(User $student, string $weekStart): ?WeeklyReport
    {
        $hafta = LocalDay::weekStart($weekStart);

        if (! $this->isFinished($hafta)) {
            return null;
        }

        $mevcut = WeeklyReport::where('student_id', $student->id)
            ->whereDate('week_start', $hafta)
            ->first();

        if ($mevcut !== null) {
            return $mevcut;
        }

        return WeeklyReport::create([
            'student_id' => $student->id,
            'week_start' => $hafta,
            'payload' => $this->compute($student, $hafta),
            'generated_at' => now(),
        ]);
    }

    /**
     * Sayilari yeniden hesaplar - ama KOC YORUMUNU korur.
     *
     * Onaylar gecikirse rapor eksik uretilmis olabilir. Yorum insan emegi;
     * sayilarla birlikte silinmesi kabul edilemez. coach_comment'in payload
     * icinde degil ayri sutunda olmasinin sebebi tam olarak bu.
     */
    public function regenerate(User $student, string $weekStart): ?WeeklyReport
    {
        $rapor = $this->for($student, $weekStart);

        if ($rapor === null) {
            return null;
        }

        $rapor->update([
            'payload' => $this->compute($student, $rapor->week_start->toDateString()),
            'generated_at' => now(),
        ]);

        return $rapor->fresh();
    }

    /**
     * Hafta kapandi mi?
     *
     * SUREN HAFTA RAPORLANMAZ: yarim haftayi dondurmak, sali gunu acan
     * veliye haftanin TAMAMI gibi gorunurdu ve o sayi bir daha duzelmezdi.
     */
    public function isFinished(string $weekStart): bool
    {
        return LocalDay::weekStart($weekStart) < LocalDay::weekStart(LocalDay::today());
    }

    /**
     * @return array<string,mixed>
     */
    private function compute(User $student, string $hafta): array
    {
        [$bas, $son] = LocalDay::weekBounds($hafta);
        $oncekiHafta = PlanPeriod::Week->shift($hafta, -1);
        [$oncekiBas, $oncekiSon] = LocalDay::weekBounds($oncekiHafta);

        $haftaSonu = Carbon::parse($hafta, LocalDay::timezone())->addDays(6)->toDateString();
        [$planBiten, $planToplam] = StudyPlanItem::progress($student, PlanPeriod::Week, $hafta);
        $denemeler = $this->examsInWeek($student, $hafta, $haftaSonu);

        return [
            'attended_days' => count($this->istatistik->attendedDays($student, $hafta, $haftaSonu)),
            // StudyStats yalnizca ONAYLI oturumlari sayiyor (scopeCountable);
            // rapor da oyle - veliye gosterilen sure dogrulanmis suredir.
            'minutes' => $this->istatistik->minutesBetween($student, $bas, $son),
            'previous_minutes' => $this->istatistik->minutesBetween($student, $oncekiBas, $oncekiSon),
            // O HAFTA yururlukte olan hedef. Bugunku hedefi yazsaydik, hedefi
            // sonradan yukseltmek gecmis haftalarin "tuttu mu" cevabini
            // degistirirdi (Dalga 5 karari).
            'goal_minutes' => StudyGoal::activeFor($student, $hafta)?->target_minutes,
            'plan_done' => $planBiten,
            'plan_total' => $planToplam,
            'exams' => $denemeler,
            'net_change' => $this->netChange($student, $hafta, $denemeler),
            // Calisma kaydi toplamlari (Dalga 28): ogrencinin beyani.
            'logged' => \App\Models\StudyLog::totals(
                \App\Models\StudyLog::where('student_id', $student->id)->betweenLocalDays($hafta, $haftaSonu)->get()
            ),
        ];
    }

    /**
     * O hafta girilen deneme sonuclari.
     *
     * @return list<array{title:string,date:string,net:float}>
     */
    private function examsInWeek(User $student, string $hafta, string $haftaSonu): array
    {
        return ExamResult::where('student_id', $student->id)
            ->with(['event', 'subjects'])
            ->get()
            ->filter(fn (ExamResult $sonuc) => $sonuc->event !== null
                && $sonuc->event->exam_date->toDateString() >= $hafta
                && $sonuc->event->exam_date->toDateString() <= $haftaSonu)
            ->sortBy(fn (ExamResult $sonuc) => $sonuc->event->exam_date->toDateString())
            ->map(fn (ExamResult $sonuc) => [
                'title' => (string) $sonuc->event->title,
                'date' => $sonuc->event->exam_date->toDateString(),
                'net' => $sonuc->totalNet(),
            ])
            ->values()
            ->all();
    }

    /**
     * Bu haftanin SON denemesi ile ondan onceki deneme arasindaki net farki.
     *
     * Onceki deneme yoksa null: ilk denemeyi sifirla karsilastirmak
     * "50 net artis" gibi anlamsiz bir mujde uretirdi.
     *
     * @param  list<array{title:string,date:string,net:float}>  $denemeler
     */
    private function netChange(User $student, string $hafta, array $denemeler): ?float
    {
        if ($denemeler === []) {
            return null;
        }

        $sonuncu = end($denemeler);

        $onceki = ExamResult::where('student_id', $student->id)
            ->with(['event', 'subjects'])
            ->get()
            ->filter(fn (ExamResult $sonuc) => $sonuc->event !== null
                && $sonuc->event->exam_date->toDateString() < $hafta)
            ->sortByDesc(fn (ExamResult $sonuc) => $sonuc->event->exam_date->toDateString())
            ->first();

        if ($onceki === null) {
            return null;
        }

        return round($sonuncu['net'] - $onceki->totalNet(), 2);
    }
}
