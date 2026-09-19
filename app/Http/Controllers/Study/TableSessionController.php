<?php

namespace App\Http\Controllers\Study;

use App\Http\Controllers\Controller;
use App\Models\StudyTable;
use App\Services\StudySessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Masadaki QR okutulunca gelinen ekran ve oturum baslatma.
 *
 * Rota adi 'table.scan': masa QR/yazdirma ekranlarindaki "henuz yayinda degil"
 * uyarisi bu adin VARLIGINA bagli ve bu dosyayla birlikte kendiliginden kalkar.
 */
class TableSessionController extends Controller
{
    public function __construct(private readonly StudySessionService $sessions)
    {
    }

    public function show(StudyTable $table): View
    {
        $kullanici = auth()->user();

        return view('study.scan', [
            'table' => $table,
            'session' => $this->sessions->openFor($kullanici),
            'occupant' => $this->sessions->openFor($kullanici) === null
                ? \App\Models\StudySession::open()->where('study_table_id', $table->id)->with('student')->first()
                : null,
        ]);
    }

    public function start(StudyTable $table): RedirectResponse
    {
        if (! $table->is_active) {
            return back()->with('error', 'Bu masa şu anda kullanımda değil.');
        }

        $kullanici = auth()->user();

        if (! $kullanici->hasRole(\App\Enums\Role::Student)) {
            return back()->with('error', 'Çalışma oturumu yalnızca öğrenciler içindir.');
        }

        $oturum = $this->sessions->start($kullanici, $table);

        return redirect()
            ->route('table.scan', $table->qr_code)
            ->with('success', "{$table->name} için çalışma başladı. Kolay gelsin!");
    }
}
