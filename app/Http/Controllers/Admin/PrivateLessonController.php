<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PrivateLessonSlot;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Ozel ders saatleri (Dalga 25). Yalnizca yonetici (Cahit Hoca) duzenler;
 * rota grubu 'admin' middleware'i altinda.
 */
class PrivateLessonController extends Controller
{
    private const SAAT = 'date_format:H:i';

    public function store(Request $request, User $student): RedirectResponse
    {
        abort_unless($student->isStudent(), 404);

        if (! $student->entitlements()->privateLessons) {
            return back()->with('error', 'Öğrencinin paketi özel ders içermiyor.');
        }

        $veri = $request->validate([
            'weekday' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', self::SAAT],
            'ends_at' => ['required', self::SAAT, 'after:starts_at'],
        ], ['ends_at.after' => 'Bitiş saati başlangıçtan sonra olmalı.']);

        $student->privateLessonSlots()->create($veri + [
            'starts_on' => LocalDay::today(),
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Özel ders saati eklendi.');
    }

    public function cancel(Request $request, PrivateLessonSlot $slot): RedirectResponse
    {
        $tarih = $this->occurrenceDate($request, $slot);

        $slot->exceptions()->updateOrCreate(['date' => $tarih], [
            'cancelled' => true, 'new_date' => null, 'new_starts_at' => null, 'new_ends_at' => null,
        ]);

        return back()->with('success', 'Ders iptal edildi.');
    }

    public function move(Request $request, PrivateLessonSlot $slot): RedirectResponse
    {
        $tarih = $this->occurrenceDate($request, $slot);

        $veri = $request->validate([
            'new_date' => ['required', 'date_format:Y-m-d'],
            'new_starts_at' => ['required', self::SAAT],
            'new_ends_at' => ['required', self::SAAT, 'after:new_starts_at'],
        ], ['new_ends_at.after' => 'Bitiş saati başlangıçtan sonra olmalı.']);

        $slot->exceptions()->updateOrCreate(['date' => $tarih], $veri + ['cancelled' => false]);

        return back()->with('success', 'Ders taşındı.');
    }

    public function destroy(PrivateLessonSlot $slot): RedirectResponse
    {
        $slot->delete();

        return back()->with('success', 'Özel ders saati kaldırıldı.');
    }

    /** Istisna yalnizca bu saatin GERCEKTEN ders oldugu bir gune yazilir. */
    private function occurrenceDate(Request $request, PrivateLessonSlot $slot): string
    {
        $tarih = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];

        if (Carbon::parse($tarih)->dayOfWeekIso !== $slot->weekday) {
            throw ValidationException::withMessages(['date' => 'Bu tarihte bu saatte ders yok.']);
        }

        return $tarih;
    }
}
