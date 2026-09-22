<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudySession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Oturum onay kuyrugu (Dalga 9).
 *
 * Kuyrugun kendisi canli ekranda yasiyor: yonetici zaten gun boyu orayi acik
 * tutuyor ve kimin iceride oldugunu oradan goruyor. Ayri bir sayfa, gunde bir
 * kez ugranan bir yer olurdu.
 *
 * Yetki AdminMiddleware'den geliyor (tum /yonetim grubu); onay kafede fiilen
 * bulunmayi gerektiren bir dogrulama oldugu icin kocla paylasilmiyor.
 */
class SessionApprovalController extends Controller
{
    public function approve(StudySession $session): RedirectResponse
    {
        $session->approve($this->yonetici());

        return back()->with('success', 'Oturum onaylandı.');
    }

    public function reject(Request $request, StudySession $session): RedirectResponse
    {
        $dogrulanmis = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $session->reject($this->yonetici(), $dogrulanmis['reason']);

        return back()->with('success', 'Oturum reddedildi.');
    }

    /**
     * Toplu onay. Gunde yirmi oturumu tek tek onaylamak, ozelligin
     * kullanilmamasi demek - kuyruk birikir ve ogrencinin suresi donar.
     */
    public function approveMany(Request $request): RedirectResponse
    {
        $dogrulanmis = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $adet = StudySession::approveMany($dogrulanmis['ids'], $this->yonetici());

        return back()->with('success', "{$adet} oturum onaylandı.");
    }

    private function yonetici(): \App\Models\User
    {
        return auth()->user();
    }
}
