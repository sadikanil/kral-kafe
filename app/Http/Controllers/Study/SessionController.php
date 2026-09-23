<?php

namespace App\Http\Controllers\Study;

use App\Enums\PauseKind;
use App\Http\Controllers\Controller;
use App\Services\StudySessionService;
use App\Support\BreakReminders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Oturumu bitirme ve calisma sayaci. Masaya bagli DEGIL: QR yalnizca masayi
 * o gun icin secer; duraklatma, mola ve devam sayactan yapilir (Dalga 23).
 */
class SessionController extends Controller
{
    public function __construct(private readonly StudySessionService $sessions)
    {
    }

    public function timer(): View|RedirectResponse
    {
        $oturum = $this->sessions->openFor(auth()->user());

        if ($oturum === null) {
            // "Calisma bitti" mesaji bu ikinci yonlendirmede kaybolmasin.
            session()->reflash();

            return redirect()->route('user.dashboard');
        }

        $oturum->load(['table', 'pauses', 'subject']);

        return view('study.timer', [
            'session' => $oturum,
            'pause' => $oturum->openPause(),
            'reminders' => BreakReminders::schedule(),
            'subjects' => \App\Models\Subject::active()->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function pause(Request $request): RedirectResponse
    {
        $veri = $request->validate(['tur' => ['required', Rule::enum(PauseKind::class)]]);

        $oturum = $this->sessions->openFor(auth()->user());
        if ($oturum === null) {
            return redirect()->route('user.dashboard')->with('error', 'Açık bir çalışma oturumunuz yok.');
        }

        $this->sessions->pause($oturum, PauseKind::from($veri['tur']));

        return redirect()->route('session.timer');
    }

    public function resume(): RedirectResponse
    {
        $oturum = $this->sessions->openFor(auth()->user());
        if ($oturum !== null) {
            $this->sessions->resume($oturum);
        }

        return redirect()->route('session.timer');
    }

    public function end(): RedirectResponse
    {
        $oturum = $this->sessions->endFor(auth()->user());

        if ($oturum === null) {
            return back()->with('error', 'Açık bir çalışma oturumunuz yok.');
        }

        $sure = $oturum->duration_minutes;

        return back()->with('success', "Çalışma bitti. Süre: {$sure} dakika.");
    }
}
