<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\WeeklyReportBuilder;
use App\Support\WeekParameter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kendi haftalik raporu - ogrenci tarafi (Dalga 15a).
 *
 * SS6.1-3: veliye ne gittigini ogrenci kendi panelinde gorur, gizli izleme
 * yok. Ayni rapor, ayni parca - suzgec yok cunku suzulecek bir sey yok:
 * rapor zaten veliye acik.
 */
class WeeklyReportController extends Controller
{
    public function show(Request $request, WeeklyReportBuilder $uretici): View
    {
        $ogrenci = auth()->user();
        $hafta = WeekParameter::resolve($request->query('hafta'));

        return view('user.report', [
            'student' => $ogrenci,
            'hafta' => $hafta,
            'report' => $uretici->for($ogrenci, $hafta),
            'finished' => $uretici->isFinished($hafta),
        ]);
    }
}
