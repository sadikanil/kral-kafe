<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudySession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sure asimi anomalisine "gordum" isareti (7 Kasim 2026).
 *
 * Anomali (12 saati asan, sistemin kapattigi oturum) gorulene kadar canli
 * ekranda kalir. Isaret sureyi ya da onayi DEGISTIRMEZ: onay kuyrugu ayri
 * karar; bu yalnizca "birisi bakti" kaydi (kim, ne zaman).
 */
class AnomalyController extends Controller
{
    public function seen(Request $request, StudySession $session): RedirectResponse
    {
        abort_unless($session->isUnseenAnomaly(), 404);

        $session->markAnomalySeen($request->user());

        return back()->with('success', $session->student->name . ' için anomali görüldü olarak işaretlendi.');
    }

    public function seenAll(Request $request): RedirectResponse
    {
        $oturumlar = StudySession::unseenAnomalies()->get();

        foreach ($oturumlar as $oturum) {
            $oturum->markAnomalySeen($request->user());
        }

        return back()->with('success', $oturumlar->count() . ' anomali görüldü olarak işaretlendi.');
    }
}
