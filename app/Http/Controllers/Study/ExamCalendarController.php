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
 * Iki rol ayni veriyi gorur (takvim kafe geneli). Tek kontrolcu, tek view,
 * iki rota (rol gruplari ayri middleware tasiyor); kabuk ve menu herkes
 * icin ortak (Dalga 21).
 */
class ExamCalendarController extends Controller
{
    public function student(Request $request): View
    {
        return $this->render($request);
    }

    public function parent(Request $request): View
    {
        return $this->render($request);
    }

    private function render(Request $request): View
    {
        [$yil, $ay] = ExamCalendar::parseMonth($request->query('ay'));

        return view('exams.calendar', [
            'detay' => $request->user()->entitlements()->examClub,
            'upcoming' => ExamEvent::upcoming()->get(),
            'weeks' => ExamCalendar::weeks($yil, $ay, ExamEvent::inMonth($yil, $ay)->get()),
            'monthLabel' => ExamCalendar::monthLabel($yil, $ay),
            'neighbours' => ExamCalendar::neighbours($yil, $ay),
        ]);
    }
}
