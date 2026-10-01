<?php

namespace App\Http\Controllers;

use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\User;
use App\Support\ExamResultDetail;
use App\Support\ExamTopics;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Deneme sonucu detayi ve deneme analizi (1 Ekim 2026).
 *
 * Detay sayfasi dort rolde AYNI gorunum (exams/result): ogrenci kendisi,
 * veli kendi cocugu, koc atandigi ogrenci, yonetici herkes. Kapilar burada,
 * rol basina ayri metot: bir rolun kurali digerine sizmasin.
 *
 * Deneme analizi (koc ve yonetici): bir denemede bakabildigi ogrencilerin
 * yan yana tablosu ve en cok eksik cikan konular. Ogrencilerin birbiriyle
 * karsilastirilmasi yasagi (README SS6.1-4) ogrenci ve veli ekranlari
 * icin; koc ve yonetici zaten hepsini tek tek goruyor.
 */
class ExamResultViewController extends Controller
{
    public function student(ExamResult $result): View
    {
        abort_unless($result->student_id === auth()->id(), 404);

        return $this->detay($result, route('user.exam-results'), 'Deneme Sonuçlarım');
    }

    public function parent(User $student, ExamResult $result): View
    {
        Gate::authorize('viewAsParent', $student);
        abort_unless($result->student_id === $student->id, 404);

        return $this->detay($result, route('parent.student', $student), $student->name);
    }

    public function coach(ExamResult $result): View
    {
        $result->loadMissing(['student', 'event']);
        abort_unless(auth()->user()->canCoach($result->student), 403);

        return $this->detay($result, route('coach.exams.show', $result->event), $result->event->title, planUrl: route('coach.plan.show', $result->student));
    }

    /** Sonucu olan denemeler; her birinde bakilabilen ogrenci sayisi ve ortalama net. */
    public function index(): View
    {
        $sonuclar = $this->gorunenSonuclar(ExamResult::query());

        $denemeler = $sonuclar->groupBy('exam_event_id')->map(fn (Collection $grup) => [
            'event' => $grup->first()->event,
            'count' => $grup->count(),
            'average' => round($grup->avg(fn (ExamResult $r) => $r->totalNet()), 2),
        ])->sortByDesc(fn ($d) => $d['event']->exam_date)->values();

        return view('coach.exams.index', ['exams' => $denemeler]);
    }

    public function show(ExamEvent $examEvent): View
    {
        $sonuclar = $this->gorunenSonuclar(ExamResult::where('exam_event_id', $examEvent->id))
            ->sortByDesc(fn (ExamResult $r) => $r->totalNet())->values();

        // Sutunlar: bu denemede gorunen tum dersler, ders sirasiyla.
        $dersler = $sonuclar->flatMap(fn (ExamResult $r) => $r->subjects)
            ->pluck('subject')->filter()->unique('id')
            ->sortBy(fn ($s) => [$s->sort_order, $s->name])->values();

        // En cok eksik cikan konular: kac ogrencide kuralla eksik.
        $ortakEksik = $sonuclar->flatMap(fn (ExamResult $r) => collect($r->topics ?? [])
            ->filter(fn ($k) => ExamTopics::isWeak($k))
            ->map(fn ($k) => ($k['subject'] ? $k['subject'] . ' · ' : '') . $k['topic'])
            ->unique())
            ->countBy()->sortDesc()->take(10);

        return view('coach.exams.show', [
            'event' => $examEvent,
            'results' => $sonuclar,
            'subjects' => $dersler,
            'commonWeak' => $ortakEksik,
        ]);
    }

    private function detay(ExamResult $sonuc, string $geri, string $geriEtiket, ?string $planUrl = null): View
    {
        return view('exams.result', ExamResultDetail::for($sonuc) + [
            'backUrl' => $geri,
            'backLabel' => $geriEtiket,
            'planUrl' => $planUrl,
            'showStudent' => auth()->id() !== $sonuc->student_id,
        ]);
    }

    /** Yonetici hepsini, koc yalnizca atandigi ogrencileri gorur. */
    private function gorunenSonuclar($sorgu): Collection
    {
        $ids = auth()->user()->coachableStudentIds();

        return $sorgu->when($ids !== null, fn ($q) => $q->whereIn('student_id', $ids))
            ->with(['event', 'student', 'subjects.subject'])
            ->get();
    }
}
