<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudySession;
use App\Models\StudyTable;
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

        return view('admin.live', [
            'sessions' => $acikOturumlar,
            'tableCount' => $acikMasaSayisi,
            'freeTables' => max(0, $acikMasaSayisi - $acikOturumlar->count()),
        ]);
    }
}
