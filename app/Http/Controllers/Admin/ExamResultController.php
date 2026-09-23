<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Deneme sonucu girisi (Dalga 12).
 *
 * Sonuclari YONETICI giriyor: sonuclar kuruma toplu geliyor, ogrenciden
 * girmesini beklemek hem gecikme hem hata kaynagi olurdu.
 */
class ExamResultController extends Controller
{
    public function edit(ExamEvent $examEvent, User $student): View
    {
        abort_unless($student->isStudent(), 404);

        return view('admin.exam-results.edit', [
            'event' => $examEvent,
            'student' => $student,
            'result' => $this->mevcutSonuc($examEvent, $student),
            // Dalga 30b: denemenin turu + ogrencinin alani.
            'subjects' => Subject::forExam($examEvent->exam_type, $student)->get(),
        ]);
    }

    public function store(Request $request, ExamEvent $examEvent, User $student): RedirectResponse
    {
        abort_unless($student->isStudent(), 404);

        $dogrulanmis = $request->validate([
            // Siralamalar SONRADAN aciklaniyor; hepsi istege bagli.
            'rank_institution' => ['nullable', 'integer', 'min:1'],
            'total_institution' => ['nullable', 'integer', 'min:1'],
            'rank_district' => ['nullable', 'integer', 'min:1'],
            'total_district' => ['nullable', 'integer', 'min:1'],
            'rank_city' => ['nullable', 'integer', 'min:1'],
            'total_city' => ['nullable', 'integer', 'min:1'],
            'rank_country' => ['nullable', 'integer', 'min:1'],
            'total_country' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],

            'subjects' => ['required', 'array'],
            'subjects.*.correct' => ['required', 'integer', 'min:0', 'max:200'],
            'subjects.*.wrong' => ['required', 'integer', 'min:0', 'max:200'],
            'subjects.*.blank' => ['required', 'integer', 'min:0', 'max:200'],
        ]);

        DB::transaction(function () use ($dogrulanmis, $examEvent, $student) {
            // Ayni denemeye ikinci kez girmek YENI KAYIT acmaz, mevcudu
            // gunceller: siralamalar sonradan aciklandigi icin ayni sonuc
            // birden cok kez duzenlenecek.
            $sonuc = ExamResult::updateOrCreate(
                ['exam_event_id' => $examEvent->id, 'student_id' => $student->id],
                collect($dogrulanmis)->except('subjects')->put('entered_by', auth()->id())->all(),
            );

            foreach ($dogrulanmis['subjects'] as $dersId => $sayilar) {
                $sonuc->subjects()->updateOrCreate(
                    ['subject_id' => (int) $dersId],
                    $sayilar,
                );
            }
        });

        return redirect()
            ->route('admin.exam-results.edit', [$examEvent, $student])
            ->with('success', 'Deneme sonucu kaydedildi.');
    }

    private function mevcutSonuc(ExamEvent $examEvent, User $student): ?ExamResult
    {
        return ExamResult::where('exam_event_id', $examEvent->id)
            ->where('student_id', $student->id)
            ->with('subjects')
            ->first();
    }
}
