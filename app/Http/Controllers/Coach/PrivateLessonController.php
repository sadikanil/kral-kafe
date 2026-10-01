<?php

namespace App\Http\Controllers\Coach;

use App\Http\Controllers\Controller;
use App\Models\PrivateLessonSlot;
use App\Services\PrivateLessonScheduler;
use App\Support\LocalDay;
use App\Support\PrivateLessonCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Kocun verdigi ozel dersler (1 Ekim 2026).
 *
 * Koc kendi derslerini gorur; tek dersi iptal eder ya da tasir, haftalik
 * saati degistirir. Ders EKLEMEZ ve SILMEZ (atamayi Cahit Hoca yapar).
 * Ucret, paket ve odeme bu ekranda HIC yok: koclarin ozel dersten
 * kazandiklarina karisilmiyor, koc da paket/ucret/odeme gormez.
 */
class PrivateLessonController extends Controller
{
    private const SAAT = 'date_format:H:i';

    public function __construct(private readonly PrivateLessonScheduler $dersler)
    {
    }

    public function index(): View
    {
        $koc = auth()->user();
        $bugun = LocalDay::today();

        return view('coach.lessons.index', [
            'slots' => PrivateLessonSlot::current()->where('teacher_id', $koc->id)
                ->with('student')->orderBy('weekday')->orderBy('starts_at')->get(),
            'upcoming' => PrivateLessonCalendar::forTeacher($koc, $bugun, Carbon::parse($bugun)->addWeeks(4)->toDateString()),
        ]);
    }

    public function cancel(Request $request, PrivateLessonSlot $slot): RedirectResponse
    {
        $this->kapiyiAc($slot);

        $this->dersler->cancel($slot, $this->tarih($request));

        return back()->with('success', 'Ders iptal edildi.');
    }

    public function move(Request $request, PrivateLessonSlot $slot): RedirectResponse
    {
        $this->kapiyiAc($slot);
        $tarih = $this->tarih($request);

        $this->dersler->move($slot, $tarih, $request->validate([
            'new_date' => ['required', 'date_format:Y-m-d'],
            'new_starts_at' => ['required', self::SAAT],
            'new_ends_at' => ['required', self::SAAT, 'after:new_starts_at'],
        ], ['new_ends_at.after' => 'Bitiş saati başlangıçtan sonra olmalı.']));

        return back()->with('success', 'Ders taşındı.');
    }

    /** Haftalik saati bugunden itibaren degistirir; gecmis dersler eski saatte kalir. */
    public function reschedule(Request $request, PrivateLessonSlot $slot): RedirectResponse
    {
        $this->kapiyiAc($slot);

        $this->dersler->reschedule($slot, $request->validate([
            'weekday' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', self::SAAT],
            'ends_at' => ['required', self::SAAT, 'after:starts_at'],
        ], ['ends_at.after' => 'Bitiş saati başlangıçtan sonra olmalı.']));

        return back()->with('success', 'Haftalık ders saati değişti.');
    }

    /** Yalnizca dersi veren koc (ya da yonetici). Baskasinin dersi 403. */
    private function kapiyiAc(PrivateLessonSlot $slot): void
    {
        $bakan = auth()->user();

        abort_unless($bakan->isAdmin() || (int) $slot->teacher_id === $bakan->id, 403);
    }

    private function tarih(Request $request): string
    {
        return $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
    }
}
