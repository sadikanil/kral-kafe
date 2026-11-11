<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\Role;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Masa yonetimi.
 *
 * SS6.2 matrisinde masa yonetimi yonetici VE gorevliye acik (7 Kasim 2026:
 * rotalar role:admin,staff). Silme yalnizca yoneticide.
 *
 * Bolum (area) ve sabit uye (assigned_student_id) istege bagli. Durum
 * (bos/dolu) saklanmaz, acik oturumdan okunur.
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
        $masalar = StudyTable::sortedByNumber(StudyTable::query()->with('assignedStudent'));

        return view('admin.tables.index', [
            'tables' => $masalar,
            'groups' => StudyTable::groupedByArea($masalar),
            // Durum saklanmaz: acik oturum varsa masa dolu (masa basina tek acik oturum).
            'occupants' => StudySession::open()->with('student')->get()->keyBy('study_table_id'),
        ]);
    }

    public function create()
    {
        return view('admin.tables.create', $this->formVerisi());
    }

    public function store(Request $request)
    {
        $validated = $this->dogrula($request);

        StudyTable::create($validated);

        return redirect()->route('admin.tables.index')
            ->with('success', 'Masa oluşturuldu. QR kodu yazdırmayı unutmayın.');
    }

    public function edit(StudyTable $table)
    {
        return view('admin.tables.edit', ['table' => $table] + $this->formVerisi());
    }

    public function update(Request $request, StudyTable $table)
    {
        $validated = $this->dogrula($request);

        // qr_code bilerek guncellenmiyor: basili etiketler gecerli kalmali.
        $table->update($validated + [
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

    /** @return array{name:string,area:?string,assigned_student_id:?int} */
    private function dogrula(Request $request): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'area' => ['nullable', 'string', 'max:40'],
            'assigned_student_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', Role::Student->value)],
        ]);

        return [
            'name' => $v['name'],
            'area' => filled($v['area'] ?? null) ? trim($v['area']) : null,
            'assigned_student_id' => isset($v['assigned_student_id']) ? (int) $v['assigned_student_id'] : null,
        ];
    }

    private function formVerisi(): array
    {
        return [
            // Bolum serbest metin; var olanlar oneri olarak sunulur (datalist).
            'areas' => StudyTable::whereNotNull('area')->distinct()->orderBy('area')->pluck('area'),
            'students' => User::where('role', Role::Student->value)->orderBy('name')->get(['id', 'name']),
        ];
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
