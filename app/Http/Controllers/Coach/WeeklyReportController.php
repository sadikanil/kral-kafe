<?php

namespace App\Http\Controllers\Coach;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WeeklyReportBuilder;
use App\Support\WeekParameter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Haftalik veli raporu - koc tarafi (Dalga 15a).
 *
 * Koc sayilari gorur ve YORUM yazar. Yorum raporun tek insan uretimi
 * parcasi; SS6.1-6 geregi sifati insan uretir, sistem sayi gosterir.
 */
class WeeklyReportController extends Controller
{
    public function __construct(private WeeklyReportBuilder $uretici)
    {
    }

    public function show(Request $request, User $student): View
    {
        $this->kapiyiAc($student);

        $hafta = WeekParameter::resolve($request->query('hafta'));

        return view('coach.report', [
            'student' => $student,
            'hafta' => $hafta,
            'report' => $this->uretici->for($student, $hafta),
            'finished' => $this->uretici->isFinished($hafta),
        ]);
    }

    public function comment(Request $request, User $student): RedirectResponse
    {
        $this->kapiyiAc($student);

        $dogrulanmis = $request->validate([
            'coach_comment' => ['nullable', 'string', 'max:2000'],
        ], [], ['coach_comment' => 'yorum']);

        $hafta = WeekParameter::resolve($request->query('hafta'));
        $rapor = $this->uretici->for($student, $hafta);

        // Hafta bitmemisse rapor da yok; yoruma yer yok.
        abort_if($rapor === null, 404);

        $yorum = trim((string) ($dogrulanmis['coach_comment'] ?? ''));
        $rapor->update(['coach_comment' => $yorum === '' ? null : $yorum]);

        return back()->with('success', 'Yorum kaydedildi.');
    }

    /**
     * Gecikmis onaydan sonra sayilari tazeler.
     *
     * Rapor BILEREK dondurulmus (velinin gordugu sayi altindan kaymasin);
     * tek kacis yolu bu ACIK islem. Koc yorumu korunur.
     */
    public function regenerate(Request $request, User $student): RedirectResponse
    {
        $this->kapiyiAc($student);

        $rapor = $this->uretici->regenerate($student, WeekParameter::resolve($request->query('hafta')));

        abort_if($rapor === null, 404);

        return back()->with('success', 'Rapor yeniden hesaplandı.');
    }

    private function kapiyiAc(?User $student): void
    {
        abort_if($student === null || ! $student->isStudent(), 404);
        abort_unless(auth()->user()->canCoach($student), 403);
    }
}
