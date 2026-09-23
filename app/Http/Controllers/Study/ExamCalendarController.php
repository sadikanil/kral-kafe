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
        $haftalar = ExamCalendar::weeks($yil, $ay, ExamEvent::inMonth($yil, $ay)->get());

        // Ozel ders (Dalga 25): ogrencinin kendi takviminde, izgaranin
        // gorunen tum gunleri icin.
        $kullanici = $request->user();
        $dersler = $kullanici->isStudent() && $kullanici->entitlements()->privateLessons
            ? collect(\App\Support\PrivateLessonCalendar::between(
                $kullanici, $haftalar[0][0]['date'], end($haftalar)[6]['date']
            ))->groupBy('date')->all()
            : [];

        return view('exams.calendar', [
            'lessons' => $dersler,
            'detay' => $request->user()->entitlements()->examClub,
            'upcoming' => ExamEvent::upcoming()->get(),
            'flexible' => ExamEvent::flexibleOpen()->get(),
            'weeks' => $haftalar,
            'monthLabel' => ExamCalendar::monthLabel($yil, $ay),
            'neighbours' => ExamCalendar::neighbours($yil, $ay),
        ]);
    }
}
