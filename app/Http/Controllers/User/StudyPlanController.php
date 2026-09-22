<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\StudyPlanItem;
use Illuminate\Http\RedirectResponse;

/**
 * Haftalik calisma plani - ogrenci tarafi (Dalga 13).
 *
 * Ogrenci yalnizca KENDI maddesini tamamlayabilir. Plani yonetici
 * belirliyor; ogrenci madde ekleyemez, silemez, degistiremez.
 */
class StudyPlanController extends Controller
{
    public function complete(StudyPlanItem $item): RedirectResponse
    {
        // Sahiplik kontrolu ACIK: policy olmadan da kirilmamali, cunku bu
        // tek satir "baskasinin planini isaretleme" sinirinin tamami.
        abort_unless($item->student_id === auth()->id(), 403);

        $item->markDone();

        return back()->with('success', 'Tamamlandı olarak işaretlendi.');
    }
}
