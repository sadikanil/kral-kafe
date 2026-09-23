<?php

namespace App\Http\Controllers\Study;

use App\Enums\StudyUnit;
use App\Http\Controllers\Controller;
use App\Models\StudyLog;
use App\Services\StudySessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Calisma kaydi (Dalga 28): sayactan "Tarih · 200 soru".
 *
 * Yalnizca ACIK oturuma yazilir ve yalnizca oturum aciksa silinir: bitmis
 * gunun kaydi veli ve kocun gordugu kayittir, sonradan kaybolmamali.
 */
class StudyLogController extends Controller
{
    public function __construct(private readonly StudySessionService $sessions)
    {
    }

    public function store(Request $request): RedirectResponse
    {
        $oturum = $this->sessions->openFor(auth()->user());
        if ($oturum === null) {
            return redirect()->route('user.dashboard')->with('error', 'Açık bir çalışma oturumunuz yok.');
        }

        $veri = $request->validate([
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000'],
            'unit' => ['required', Rule::enum(StudyUnit::class)],
            'note' => ['nullable', 'string', 'max:120'],
        ], ['amount.min' => 'En az 1 olmalı.']);

        StudyLog::create([
            'student_id' => $oturum->student_id,
            'study_session_id' => $oturum->id,
            'subject_id' => $veri['subject_id'] ?? null,
            'amount' => $veri['amount'],
            'unit' => $veri['unit'],
            'note' => $veri['note'] ?? null,
        ]);

        // Oturumun dersi son kayittan gelir (eski ders secimi kalkti).
        // "Genel" kayit onceki dersi silmez.
        if (! empty($veri['subject_id'])) {
            $oturum->update(['subject_id' => $veri['subject_id']]);
        }

        return redirect()->route('session.timer')->with('success', 'Kaydedildi 💪');
    }

    public function destroy(StudyLog $log): RedirectResponse
    {
        abort_unless($log->student_id === auth()->id(), 403);
        abort_unless($log->session?->ended_at === null, 403);

        $log->delete();

        return redirect()->route('session.timer');
    }
}
