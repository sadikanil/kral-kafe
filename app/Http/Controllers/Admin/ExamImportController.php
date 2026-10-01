<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExamType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Models\ExamImport;
use App\Models\ExamImportRow;
use App\Models\User;
use App\Services\ExamImport\ExamImportProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kurum geneli deneme sonuc PDF'i (1 Ekim 2026).
 *
 * Yonetici tek yerden yukler: PDF takvimdeki denemeye baglanir, yapay
 * zeka sayfa sayfa okur, satirlar ogrencilere eslenir; yonetici kontrol
 * edip yayinlar. Ogrenci, veli ve koc yalnizca o ogrencinin sonucunu ve
 * eksik konularini gorur; PDF'in kendisi (tum kurumun adlari ve puanlari)
 * yalnizca burada indirilir (README SS6.1-4).
 */
class ExamImportController extends Controller
{
    public function __construct(private readonly ExamImportProcessor $isleyici)
    {
    }

    public function index(): View
    {
        return view('admin.exam-imports.index', [
            'imports' => ExamImport::with('event')->withCount('rows')->latest()->get(),
            'events' => ExamEvent::whereIn('exam_type', [ExamType::Tyt->value, ExamType::Ayt->value, ExamType::TytAyt->value])
                ->orderByDesc('exam_date')->limit(40)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $veri = $request->validate([
            'exam_event_id' => ['required', 'integer', Rule::exists('exam_events', 'id')],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ], [], ['exam_event_id' => 'deneme', 'pdf' => 'PDF']);

        $yol = $request->file('pdf')->storeAs('deneme-aktarimlari', Str::uuid() . '.pdf', config('filesystems.uploads'));

        $aktarim = ExamImport::create([
            'exam_event_id' => $veri['exam_event_id'],
            'file_path' => $yol,
            'uploaded_by' => auth()->id(),
        ]);

        return redirect()->route('admin.exam-imports.show', $aktarim)
            ->with('success', 'PDF yüklendi. Okuma başlıyor; sayfa açık kalsın.');
    }

    public function show(ExamImport $import): View
    {
        $import->load(['event', 'rows.student']);

        return view('admin.exam-imports.show', [
            'import' => $import,
            'progress' => $this->isleyici->durum($import),
            'students' => User::where('role', Role::Student->value)->orderBy('name')->get(),
            'subjects' => $this->isleyici->dersler($import->event->exam_type),
        ]);
    }

    /** Tek adim (dizin ya da bir karne). Sayfadaki betik bitene kadar cagirir. */
    public function process(Request $request, ExamImport $import): JsonResponse|RedirectResponse
    {
        $durum = $this->isleyici->step($import);

        return $request->expectsJson()
            ? response()->json($durum)
            : back()->with($durum['status'] === ExamImport::FAILED ? 'error' : 'success',
                $durum['message'] ?? 'Okundu: ' . $durum['done'] . ' / ' . $durum['total'] . ' karne.');
    }

    /** Satirin ogrencisini secer ya da satiri atlar. */
    public function updateRow(Request $request, ExamImportRow $row): RedirectResponse
    {
        $veri = $request->validate([
            'student_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', Role::Student->value)],
            'skip' => ['nullable', 'boolean'],
        ], [], ['student_id' => 'öğrenci']);

        if ($request->boolean('skip')) {
            $row->update(['student_id' => null, 'match' => ExamImportRow::SKIP]);

            return back()->with('success', "{$row->name} atlandı.");
        }

        $id = $veri['student_id'] ?? null;

        // Ayni ogrenci ayni PDF'te iki satira baglanmaz.
        if ($id !== null && $row->import->rows()->whereKeyNot($row->id)->where('student_id', $id)
            ->whereIn('match', [ExamImportRow::AUTO, ExamImportRow::MANUAL, ExamImportRow::SUGGESTED])->exists()) {
            return back()->with('error', 'Bu öğrenci başka bir satıra bağlı.');
        }

        $row->update(['student_id' => $id, 'match' => $id === null ? ExamImportRow::NONE : ExamImportRow::MANUAL]);

        return back()->with('success', "{$row->name} kaydedildi.");
    }

    public function publish(ExamImport $import): RedirectResponse
    {
        if (! in_array($import->status, [ExamImport::REVIEW, ExamImport::PUBLISHED], true)) {
            return back()->with('error', 'Önce tüm sayfaların okunması bitmeli.');
        }

        $bekleyen = $import->rows()->whereIn('match', [ExamImportRow::SUGGESTED, ExamImportRow::NONE])->count();
        if ($bekleyen > 0) {
            return back()->with('error', "{$bekleyen} satır kontrol bekliyor: öğrenciyi seçin ya da atlayın.");
        }

        $sayi = $this->isleyici->publish($import, auth()->user());

        return back()->with('success', "{$sayi} öğrencinin sonucu yayınlandı; öğrenci, veli ve koçlara bildirim gitti.");
    }

    /** PDF yalnizca yoneticiye: tum kurumun adlari ve puanlari icinde. */
    public function pdf(ExamImport $import)
    {
        $disk = Storage::disk(config('filesystems.uploads'));
        abort_unless($disk->exists($import->file_path), 404, 'Dosya bulunamadı.');

        return $disk->response($import->file_path, Str::slug($import->event->title) . '.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** Aktarim ve PDF silinir; yayinlanmis sonuclar ogrencide kalir. */
    public function destroy(ExamImport $import): RedirectResponse
    {
        Storage::disk(config('filesystems.uploads'))->delete($import->file_path);
        $import->delete();

        return redirect()->route('admin.exam-imports.index')->with('success', 'Aktarım silindi; yayınlanmış sonuçlar duruyor.');
    }
}
