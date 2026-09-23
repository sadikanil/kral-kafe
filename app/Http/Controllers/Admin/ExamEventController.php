<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExamType;
use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Support\ExamCalendar;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Deneme sinavi takvimi yonetimi.
 *
 * Takvim kafe geneli: bir deneme herkese gorunur. Ogrenci basina deneme
 * (ve sonuc) FEATURE 6 ile gelecek; bu ekran yalnizca "ne zaman, hangi
 * deneme" sorusunu cevaplar.
 */
class ExamEventController extends Controller
{
    public function index(Request $request)
    {
        [$yil, $ay] = ExamCalendar::parseMonth($request->query('ay'));

        return view('admin.exams.index', [
            'upcoming' => ExamEvent::upcoming()->get(),
            'flexible' => ExamEvent::flexibleOpen()->get(),
            'past' => ExamEvent::past()->limit(10)->get(),
            'weeks' => ExamCalendar::weeks($yil, $ay, ExamEvent::inMonth($yil, $ay)->get()),
            'monthLabel' => ExamCalendar::monthLabel($yil, $ay),
            'neighbours' => ExamCalendar::neighbours($yil, $ay),
        ]);
    }

    public function create()
    {
        return view('admin.exams.create');
    }

    public function store(Request $request)
    {
        $veri = $this->dogrula($request);
        $veri['created_by'] = auth()->id();

        ExamEvent::create($veri);

        return redirect()->route('admin.exams.index')
            ->with('success', 'Deneme takvime eklendi.');
    }

    public function edit(ExamEvent $exam)
    {
        return view('admin.exams.edit', ['exam' => $exam]);
    }

    public function update(Request $request, ExamEvent $exam)
    {
        $exam->update($this->dogrula($request));

        return redirect()->route('admin.exams.index')
            ->with('success', 'Deneme güncellendi.');
    }

    public function destroy(ExamEvent $exam)
    {
        $exam->delete();

        return redirect()->route('admin.exams.index')
            ->with('success', 'Deneme takvimden kaldırıldı.');
    }

    /**
     * @return array<string,mixed>
     */
    private function dogrula(Request $request): array
    {
        $veri = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'exam_type' => ['required', Rule::enum(ExamType::class)],
            'exam_date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:255'],
            // Serbest deneme (Dalga 30a): tarih pencerenin ilk gunu.
            'is_flexible' => ['nullable', 'boolean'],
            'available_until' => ['nullable', 'required_if_accepted:is_flexible', 'date_format:Y-m-d', 'after_or_equal:exam_date'],
        ], ['available_until.required_if_accepted' => 'Serbest denemenin son gününü seçin.']);

        $veri['starts_at'] = $veri['starts_at'] ?? null;
        $veri['note'] = $veri['note'] ?? null;
        $veri['is_flexible'] = $request->boolean('is_flexible');
        $veri['available_until'] = $veri['is_flexible'] ? $veri['available_until'] : null;

        return $veri;
    }
}
