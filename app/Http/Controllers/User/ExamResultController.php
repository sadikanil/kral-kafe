<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ExamResult;
use Illuminate\View\View;

/**
 * Ogrencinin kendi deneme sonuclari (Dalga 12).
 *
 * Salt okunur: sonuclari yonetici giriyor.
 */
class ExamResultController extends Controller
{
    public function index(): View
    {
        return view('user.exam-results', [
            'examResults' => ExamResult::where('student_id', auth()->id())
                ->with(['event', 'subjects.subject'])
                ->get()
                ->sortByDesc(fn (ExamResult $sonuc) => $sonuc->event->exam_date)
                ->values(),
        ]);
    }
}
