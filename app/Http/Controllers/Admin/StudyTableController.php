<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudyTable;
use Illuminate\Http\Request;

/**
 * Masa yonetimi.
 *
 * §7 matrisinde masa yonetimi yonetici VE gorevliye acik. Gorevlinin kendi
 * paneli henuz yok ve AdminMiddleware yalnizca yoneticiyi geciriyor; gorevli
 * erisimi kendi paneliyle birlikte gelecek.
 */
class StudyTableController extends Controller
{
    /**
     * Tum yerler tek sayfada, sayi sirasinda ("Masa 2" "Masa 10"dan once).
     * SQL'de iki surucude de calisan dogal siralama yok; kafede 32 yer var,
     * siralama bellekte yapilir. Sayfalama sirayi sayfalar arasinda bolerdi.
     */
    public function index()
    {
        return view('admin.tables.index', [
            'tables' => StudyTable::sortedByNumber(StudyTable::query()),
        ]);
    }

    public function create()
    {
        return view('admin.tables.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        StudyTable::create(['name' => $validated['name']]);

        return redirect()->route('admin.tables.index')
            ->with('success', 'Masa oluşturuldu. QR kodu yazdırmayı unutmayın.');
    }

    public function edit(StudyTable $table)
    {
        return view('admin.tables.edit', ['table' => $table]);
    }

    public function update(Request $request, StudyTable $table)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        // qr_code bilerek guncellenmiyor: basili etiketler gecerli kalmali.
        $table->update([
            'name' => $validated['name'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.tables.index')
            ->with('success', 'Masa güncellendi.');
    }

    /**
     * Gecmis kayit, masayi silmek icin feda edilmemeli. Yabanci anahtar zaten
     * engelliyor ama yakalanmazsa 500 doner ve yonetici sebebini goremez;
     * kullanimdan kalkan masa silinmez, KAPATILIR.
     */
    public function destroy(StudyTable $table)
    {
        if ($table->sessions()->exists()) {
            return back()->with('error', 'Bu masada çalışma kaydı var; silinemez. Masayı kapatabilirsiniz.');
        }

        $table->delete();

        return redirect()->route('admin.tables.index')
            ->with('success', 'Masa silindi.');
    }

    public function toggleStatus(StudyTable $table)
    {
        $table->update(['is_active' => ! $table->is_active]);

        return back()->with('success', 'Masa durumu güncellendi.');
    }

    public function qr(StudyTable $table)
    {
        return view('admin.tables.qr', ['table' => $table]);
    }

    /**
     * Yazdirma sayfasi yalnizca ACIK masalari alir: kapali bir masanin etiketini
     * basmak, okutulunca ise yaramayan bir etiket duvara yapistirmak demek.
     */
    public function printQr()
    {
        return view('admin.tables.print-qr', [
            'tables' => StudyTable::sortedByNumber(StudyTable::active()),
        ]);
    }
}
