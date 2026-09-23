<?php

namespace App\Http\Controllers\Coach;

use App\Enums\CoachNoteKind;
use App\Enums\NoteVisibility;
use App\Http\Controllers\Controller;
use App\Models\CoachNote;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Koc notlari ve koc-veli gorusme kaydi (Dalga 14b).
 *
 * Gorusme kaydi SOHBET DEGIL (SS7-I): tek yonlu bir kayit, WhatsApp'in
 * yerini almaya calismaz - konusulani kayit altina alir. Ayri tablo yok,
 * kind = meeting.
 */
class NoteController extends Controller
{
    public function index(User $student): View
    {
        $this->kapiyiAc($student);

        return view('coach.notes.index', [
            'student' => $student,
            // Koc HER IKI turu de gorur; suzgec yalnizca veli ve ogrenci
            // tarafinda. En yeni ustte: son gozlem en cok ise yarayan.
            'notes' => CoachNote::forStudent($student)
                ->with('author')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request, User $student): RedirectResponse
    {
        // YETKI ONCE, dogrulama sonra: atanmamis ogrenci icin gonderilen
        // bozuk form 422 dönseydi sinirin varligini sizdirirdi.
        $this->kapiyiAc($student);

        $dogrulanmis = $request->validate([
            'kind' => ['required', Rule::enum(CoachNoteKind::class)],
            'visibility' => ['nullable', Rule::enum(NoteVisibility::class)],
            'body' => ['required', 'string', 'max:2000'],
            // Zorunlulugu tur belirliyor; kurali BILEN taraf burasi.
            'occurred_on' => [
                Rule::requiredIf(fn () => CoachNoteKind::tryFrom((string) $request->input('kind'))?->needsDate() === true),
                'nullable',
                'date',
            ],
        ], [
            'occurred_on.required' => 'Görüşmenin hangi gün yapıldığını yaz.',
        ], [
            // Formdaki etiketler; dil dosyasinda bu anahtarlarin karsiligi yok.
            'kind' => 'tür',
            'visibility' => 'görünürlük',
            'body' => 'not',
            'occurred_on' => 'görüşme günü',
        ]);

        $govde = trim($dogrulanmis['body']);

        if ($govde === '') {
            return back()->withErrors(['body' => 'Not boş olamaz.'])->withInput();
        }

        $tur = CoachNoteKind::from($dogrulanmis['kind']);

        CoachNote::create([
            'student_id' => $student->id,
            'created_by' => auth()->id(),
            'kind' => $tur->value,
            // Gorunurluk verilmezse VELIYE ACIK (karar 6). Varsayilanin
            // kapali olmasi SS6.1-2'nin tersine calisirdi.
            'visibility' => $dogrulanmis['visibility'] ?? NoteVisibility::Parent->value,
            'body' => $govde,
            // Duz notta tarih tutulmaz: yazildigi an olayin kendisi ve
            // created_at onu zaten tasiyor - ikinci bir dogruluk kaynagi
            // olurdu.
            'occurred_on' => $tur->needsDate() ? $dogrulanmis['occurred_on'] : null,
        ]);

        return back()->with('success', $tur->label() . ' kaydedildi.');
    }

    public function destroy(CoachNote $note): RedirectResponse
    {
        $this->kapiyiAc($note->student);

        $note->delete();

        return back()->with('success', 'Kayıt silindi.');
    }

    /**
     * Ogrenci gecerli mi ve bakan kisi onun kocu gibi davranabilir mi?
     *
     * StudyPlanController ile AYNI kapi (User::canCoach) - plan ve not ayni
     * sinira tabi.
     */
    private function kapiyiAc(?User $student): void
    {
        abort_if($student === null || ! $student->isStudent(), 404);
        abort_unless(auth()->user()->canCoach($student), 403);
    }
}
