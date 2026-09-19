<?php

namespace App\Http\Controllers\Study;

use App\Http\Controllers\Controller;
use App\Services\StudySessionService;
use Illuminate\Http\RedirectResponse;

/**
 * Oturumu bitirme. Masaya bagli DEGIL: ogrenci hem QR ekranindan hem kendi
 * panelinden bitirebiliyor, iki ayri rota iki ayri kural demek olurdu.
 */
class SessionController extends Controller
{
    public function __construct(private readonly StudySessionService $sessions)
    {
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
