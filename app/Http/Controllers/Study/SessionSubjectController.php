<?php

namespace App\Http\Controllers\Study;

use App\Http\Controllers\Controller;
use App\Models\StudySession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Oturuma ders etiketi (Dalga 17a).
 *
 * Yalnizca ACIK oturum etiketlenir. Bitmis oturumun suresi onaya gitmis
 * olabilir; gecmisi sonradan yeniden etiketlemek kirilimi velinin gordugu
 * rapordan SONRA degistirirdi.
 */
class SessionSubjectController extends Controller
{
    public function update(Request $request, StudySession $session): RedirectResponse
    {
        // Sahiplik ACIK bir kontrol: o tek satir "baskasinin oturumunu
        // etiketleme" sinirinin tamami.
        abort_unless($session->student_id === auth()->id(), 403);
        abort_unless($session->ended_at === null, 403);

        $dogrulanmis = $request->validate([
            // Bos deger etiketi KALDIRIR - ogrenci yanlis secimi geri
            // alabilmeli.
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
        ]);

        $session->update(['subject_id' => $dogrulanmis['subject_id'] ?: null]);

        return back()->with('success', 'Ders güncellendi.');
    }
}
