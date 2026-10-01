<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PrivateLessonSlot;
use App\Models\User;
use App\Services\PrivateLessonScheduler;
use App\Services\SubscriptionOpener;
use App\Support\LocalDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ozel ders saatleri (Dalga 25). Yalnizca yonetici (Cahit Hoca) ekler ve
 * siler; rota grubu 'admin' middleware'i altinda.
 *
 * 1 Ekim 2026:
 *   - Paketten BAGIMSIZ: kocluk paketi olmayan ogrenci de ozel ders talep
 *     edebilir; ders saati paket kapisina takilmaz.
 *   - Dersi kim, hangi dersten veriyor (teacher_id, branch). Dersi veren
 *     koc kendi derslerini /koc/ozel-dersler'de gorur, iptal eder, tasir.
 *   - Toplu: ayni saat birden cok ogrenciye; ozel ders paketi birden cok
 *     ogrenciye. Ucret ve odeme yalnizca burada (koclar karismaz).
 */
class PrivateLessonController extends Controller
{
    private const SAAT = 'date_format:H:i';

    public function __construct(private readonly PrivateLessonScheduler $dersler)
    {
    }

    /** Tum ozel dersler, ogretmene gore; toplu ekleme ve toplu paket. */
    public function index(): View
    {
        $bugun = LocalDay::today();

        return view('admin.lessons.index', [
            'slots' => PrivateLessonSlot::current()->with(['student', 'teacher'])
                ->orderBy('weekday')->orderBy('starts_at')->get()
                ->groupBy(fn (PrivateLessonSlot $s) => $s->teacher?->coachLabel() ?? 'Öğretmen seçilmemiş'),
            'students' => User::where('role', Role::Student->value)->orderBy('name')->get(),
            'teachers' => User::coachCandidates()->orderBy('name')->get(),
            'packages' => Package::active()->where('includes_private_lessons', true)->orderBy('name')->get(),
            'today' => $bugun,
        ]);
    }

    /** Kullanici sayfasindan tek ogrenciye haftalik saat. */
    public function store(Request $request, User $student): RedirectResponse
    {
        abort_unless($student->isStudent(), 404);

        $this->dersler->add($student, $this->saatKurallari($request));

        return back()->with('success', 'Özel ders saati eklendi.');
    }

    /** Ayni haftalik saat birden cok ogrenciye (grup dersi ya da toplu kayit). */
    public function storeMany(Request $request): RedirectResponse
    {
        $veri = $this->saatKurallari($request, toplu: true);
        $ogrenciler = User::where('role', Role::Student->value)->whereIn('id', $veri['student_ids'])->get();

        DB::transaction(function () use ($ogrenciler, $veri) {
            foreach ($ogrenciler as $ogrenci) {
                $this->dersler->add($ogrenci, $veri);
            }
        });

        return back()->with('success', $ogrenciler->count() . ' öğrenciye özel ders saati eklendi.');
    }

    /**
     * Ozel ders paketini birden cok ogrenciye acar (ek paket olarak). Fiyat
     * paketten kopyalanir; odeme takibi Odemeler ekraninda.
     */
    public function assignPackage(Request $request, SubscriptionOpener $abonelikler): RedirectResponse
    {
        $veri = $request->validate([
            'package_id' => ['required', 'integer',
                Rule::exists('packages', 'id')->where('is_active', 1)->where('includes_private_lessons', 1)],
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', Role::Student->value)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
        ], [
            'package_id.exists' => 'Özel ders içeren, satıştaki bir paket seçin.',
            'student_ids.required' => 'En az bir öğrenci seçin.',
        ], ['student_ids' => 'öğrenciler', 'starts_on' => 'başlangıç']);

        $paket = Package::findOrFail($veri['package_id']);
        $ogrenciler = User::whereIn('id', $veri['student_ids'])->get();

        DB::transaction(function () use ($ogrenciler, $paket, $veri, $abonelikler) {
            foreach ($ogrenciler as $ogrenci) {
                $abonelikler->open($ogrenci, $paket, Carbon::parse($veri['starts_on']));
            }
        });

        return back()->with('success', "{$paket->name}: {$ogrenciler->count()} öğrenciye açıldı.");
    }

    public function cancel(Request $request, PrivateLessonSlot $slot): RedirectResponse
    {
        $this->dersler->cancel($slot, $this->tarih($request));

        return back()->with('success', 'Ders iptal edildi.');
    }

    public function move(Request $request, PrivateLessonSlot $slot): RedirectResponse
    {
        $tarih = $this->tarih($request);

        $this->dersler->move($slot, $tarih, $request->validate([
            'new_date' => ['required', 'date_format:Y-m-d'],
            'new_starts_at' => ['required', self::SAAT],
            'new_ends_at' => ['required', self::SAAT, 'after:new_starts_at'],
        ], ['new_ends_at.after' => 'Bitiş saati başlangıçtan sonra olmalı.']));

        return back()->with('success', 'Ders taşındı.');
    }

    public function destroy(PrivateLessonSlot $slot): RedirectResponse
    {
        $slot->delete();

        return back()->with('success', 'Özel ders saati kaldırıldı.');
    }

    /** @return array<string,mixed> */
    private function saatKurallari(Request $request, bool $toplu = false): array
    {
        return $request->validate([
            'weekday' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', self::SAAT],
            'ends_at' => ['required', self::SAAT, 'after:starts_at'],
            // Dersi veren: koc, yonetici ya da koc yetkili veli/ogretmen.
            'teacher_id' => [$toplu ? 'required' : 'nullable', 'integer', Rule::in(User::coachCandidates()->pluck('id')->all())],
            'branch' => ['nullable', 'string', 'max:60'],
            ...($toplu ? [
                'student_ids' => ['required', 'array', 'min:1'],
                'student_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', Role::Student->value)],
            ] : []),
        ], [
            'ends_at.after' => 'Bitiş saati başlangıçtan sonra olmalı.',
            'teacher_id.in' => 'Dersi veren kişi koç ya da yönetici olmalı.',
            'student_ids.required' => 'En az bir öğrenci seçin.',
        ], ['student_ids' => 'öğrenciler']);
    }

    private function tarih(Request $request): string
    {
        return $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
    }
}
