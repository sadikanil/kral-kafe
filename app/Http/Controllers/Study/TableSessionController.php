<?php

namespace App\Http\Controllers\Study;

use App\Http\Controllers\Controller;
use App\Models\StudyTable;
use App\Services\StudySessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

    /**
     * Kamerasiz yedek yol: masadaki kodu elle yazma ekrani.
     *
     * Okuyucu getUserMedia + BarcodeDetector ile calisiyor ve ikisi de her
     * telefonda yok: kamera izni reddedilebilir, tarayici BarcodeDetector
     * tasimayabilir, ve kamera YALNIZCA https'te (ve localhost'ta) acilir.
     * Tek yol olarak kameraya baglanmak, bu durumlarin herhangi birindeki
     * ogrenciyi sistem disina atardi.
     */
    public function scanner(): View
    {
        return view('study.scanner');
    }

    /**
     * Elle yazilan masa kodunu cozer.
     *
     * Kodlar buyuk harf uretiliyor (Str::upper) ama telefon klavyesi kucuk
     * harf yaziyor ve yapistirirken bosluk bulasiyor. Bunlari kullaniciya
     * duzelttirmek yedek yolu kullanilamaz kilardi; normallestirme burada.
     */
    public function find(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:50']]);

        $kod = Str::upper(trim($request->string('code')->toString()));
        $masa = StudyTable::where('qr_code', $kod)->first();

        if ($masa === null) {
            return back()
                ->withInput()
                ->withErrors(['code' => 'Bu kodla bir masa bulunamadı. Masadaki etikette yazan kodu kontrol et.']);
        }

        return redirect()->route('table.scan', $masa->qr_code);
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
