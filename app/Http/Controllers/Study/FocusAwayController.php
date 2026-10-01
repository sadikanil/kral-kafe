<?php

namespace App\Http\Controllers\Study;

use App\Http\Controllers\Controller;
use App\Models\StudySession;
use App\Services\StudySessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Odak modu (Faz 4): sayfa odak modundayken gorunmez olup geri gelince
 * tarayici ne kadar uzakta kalindigini bildirir. Web uygulamasi baska
 * uygulamalari engelleyemez; bunun yerine ayrilmak sayilir ve gosterilir.
 */
class FocusAwayController extends Controller
{
    /** Bildirime bir an bakmak, ekranin donmesi: cikis sayilmaz. */
    private const EN_KISA = 3;

    public function __construct(private readonly StudySessionService $sessions)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $saniye = (int) $request->validate([
            'saniye' => ['required', 'integer', 'min:' . self::EN_KISA, 'max:86400'],
        ])['saniye'];

        $oturum = $this->sessions->openFor($request->user());
        abort_if($oturum === null, 404);

        // Molada telefona bakmak serbest; odak modu da molada kapanir, bu
        // yalnizca gec gelen bildirime karsi.
        if ($oturum->openPause() === null) {
            // Uykudan donen telefon uzun bir sure bildirebilir; tek bir
            // ayrilis oturumun kendisinden uzun olamaz.
            // Tek satir, atomik: ayni anda gelen iki bildirim birbirini ezmez.
            // (Modelde incrementEach sorgu kurucusuna duser ve TUM satirlari
            // arttirirdi; kapsam whereKey ile.)
            $sinir = max(0, (int) $oturum->started_at->diffInSeconds(now()));
            StudySession::whereKey($oturum->getKey())->incrementEach([
                'focus_away_count' => 1,
                'focus_away_seconds' => min($saniye, $sinir),
            ]);
            $oturum->refresh();
        }

        return response()->json([
            'sayi' => (int) $oturum->focus_away_count,
            'saniye' => (int) $oturum->focus_away_seconds,
        ]);
    }
}
