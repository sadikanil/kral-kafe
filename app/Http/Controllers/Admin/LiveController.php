<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\SessionEndReason;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Support\LocalDay;
use Illuminate\View\View;

/**
 * Canli ekran: su an iceride kim, hangi masada, ne kadardir.
 */
class LiveController extends Controller
{
    public function index(): View
    {
        $acikOturumlar = StudySession::open()
            ->with(['student', 'table'])
            ->orderBy('started_at')
            ->get();

        $acikMasaSayisi = StudyTable::active()->count();

        // "12 saati asan oturum yoneticiye anomali olarak duser" (FEATURE 1).
        // Sessizce kapatmak kurali uygulamak sayilmaz; birinin gormesi gerek.
        [$gunBasi, $gunSonu] = LocalDay::bounds(LocalDay::today());

        $anomaliler = StudySession::where('end_reason', SessionEndReason::OverLimit)
            ->whereBetween('ended_at', [$gunBasi, $gunSonu])
            ->with(['student', 'table'])
            ->orderByDesc('ended_at')
            ->get();

        return view('admin.live', [
            'sessions' => $acikOturumlar,
            'anomalies' => $anomaliler,
            'tableCount' => $acikMasaSayisi,
            'freeTables' => max(0, $acikMasaSayisi - $acikOturumlar->count()),
        ]);
    }
}
