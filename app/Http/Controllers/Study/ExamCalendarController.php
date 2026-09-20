<?php

namespace App\Http\Controllers\Study;

use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Support\ExamCalendar;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Deneme takvimi - ogrenci ve veli icin salt okunur gorunum.
 *
 * Iki rol ayni veriyi gorur (takvim kafe geneli); yalnizca sayfa iskeleti
 * (menu) farkli. Bu yuzden tek kontrolcu, tek view, iki rota; layout rol
 * grubundan gelir.
 */
class ExamCalendarController extends Controller
{
    public function student(Request $request): View
    {
        return $this->render($request, 'layouts.user');
    }

    public function parent(Request $request): View
    {
        return $this->render($request, 'layouts.parent');
    }

    private function render(Request $request, string $layout): View
    {
        [$yil, $ay] = ExamCalendar::parseMonth($request->query('ay'));

        return view('exams.calendar', [
            'layout' => $layout,
            'upcoming' => ExamEvent::upcoming()->get(),
            'weeks' => ExamCalendar::weeks($yil, $ay, ExamEvent::inMonth($yil, $ay)->get()),
            'monthLabel' => ExamCalendar::monthLabel($yil, $ay),
            'neighbours' => ExamCalendar::neighbours($yil, $ay),
        ]);
    }
}
