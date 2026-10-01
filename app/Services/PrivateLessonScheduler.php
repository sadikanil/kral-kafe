<?php

namespace App\Services;

use App\Models\PrivateLessonException;
use App\Models\PrivateLessonSlot;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ozel ders saatleri: ekleme, tek dersi iptal/tasima, haftalik saati
 * degistirme (Dalga 25; 1 Ekim 2026'da koc tarafi eklendi).
 *
 * Yonetici (Cahit Hoca) ve dersi veren koc AYNI kurallardan gecer; iki
 * controller'da ayri yazilsaydi biri gunun birinde digerinden gevsek kalirdi.
 * Kimin neyi yapabildigi controller'da: koc yalnizca kendi dersini degistirir,
 * ekleyip silemez; ucret, paket ve odeme hic onun ekraninda degil.
 *
 * Paketten BAGIMSIZ (1 Ekim 2026): kocluk paketi olmayan ogrenci de ozel
 * ders talep edebilir; ders saati paket kapisina takilmaz.
 */
class PrivateLessonScheduler
{
    /**
     * Haftalik saat ekler. Ayni saat zaten varsa ayni sonuca doner (cift
     * tiklanan "Ekle" ikinci satir acmaz).
     *
     * Dersi veren kisi ogrencinin kocu olarak da atanir: odev verebilsin,
     * plani ve raporu gorebilsin. Yonetici zaten herkesi gorur.
     *
     * @param  array{weekday:int,starts_at:string,ends_at:string,teacher_id?:?int,branch?:?string}  $veri
     */
    public function add(User $student, array $veri): PrivateLessonSlot
    {
        $ogretmen = isset($veri['teacher_id']) ? User::find($veri['teacher_id']) : null;
        $brans = filled($veri['branch'] ?? null) ? trim($veri['branch']) : $ogretmen?->coach_subject;

        return DB::transaction(function () use ($student, $veri, $ogretmen, $brans) {
            $saat = $student->privateLessonSlots()->firstOrCreate([
                'weekday' => (int) $veri['weekday'],
                'starts_at' => $veri['starts_at'],
                'ends_at' => $veri['ends_at'],
                'teacher_id' => $ogretmen?->id,
                'ends_on' => null,
            ], [
                'branch' => $brans,
                'starts_on' => LocalDay::today(),
                'created_by' => auth()->id(),
            ]);

            if ($ogretmen !== null && ! $ogretmen->isAdmin()) {
                $student->coaches()->syncWithoutDetaching([$ogretmen->id => ['created_by' => auth()->id()]]);
            }

            return $saat;
        });
    }

    public function cancel(PrivateLessonSlot $slot, string $tarih): void
    {
        $this->assertOccurs($slot, $tarih);

        $this->exceptionOn($slot, $tarih)->fill([
            'cancelled' => true, 'new_date' => null, 'new_starts_at' => null, 'new_ends_at' => null,
        ])->save();
    }

    /** @param array{new_date:string,new_starts_at:string,new_ends_at:string} $yeni */
    public function move(PrivateLessonSlot $slot, string $tarih, array $yeni): void
    {
        $this->assertOccurs($slot, $tarih);

        $this->exceptionOn($slot, $tarih)->fill($yeni + ['cancelled' => false])->save();
    }

    /**
     * Haftalik saati bugunden itibaren degistirir.
     *
     * Satir yerinde guncellenmez: gecmis dersler eski gun/saatte kalmali
     * ("gecen sali dersi vardi"). Eski saat dun biter, yenisi bugun baslar.
     * Bugun baslamis (hic islememis) saat ise dogrudan guncellenir.
     *
     * @param  array{weekday:int,starts_at:string,ends_at:string}  $yeni
     */
    public function reschedule(PrivateLessonSlot $slot, array $yeni): PrivateLessonSlot
    {
        $bugun = LocalDay::today();

        if ($slot->starts_on->toDateString() >= $bugun) {
            $slot->update($yeni);

            return $slot;
        }

        return DB::transaction(function () use ($slot, $yeni, $bugun) {
            $slot->update(['ends_on' => Carbon::parse($bugun)->subDay()->toDateString()]);

            return PrivateLessonSlot::create([
                'student_id' => $slot->student_id,
                'teacher_id' => $slot->teacher_id,
                'branch' => $slot->branch,
                'weekday' => (int) $yeni['weekday'],
                'starts_at' => $yeni['starts_at'],
                'ends_at' => $yeni['ends_at'],
                'starts_on' => $bugun,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * O tarihin mevcut istisnasi ya da yeni (kaydedilmemis) bir tane.
     *
     * updateOrCreate(['date' => 'Y-m-d']) kullanilamaz: 'date' cast'i SQLite'ta
     * "Y-m-d 00:00:00" yazar, duz esitlik ikinci istekte satiri bulamaz ve
     * (slot, date) tekil indeksine carpip 500 verir. whereDate iki surucude de
     * eslesir (projede date sutunlarinin yerlesik yolu).
     */
    private function exceptionOn(PrivateLessonSlot $slot, string $tarih): PrivateLessonException
    {
        return $slot->exceptions()->whereDate('date', $tarih)->first()
            ?? $slot->exceptions()->make(['date' => $tarih]);
    }

    /** Istisna yalnizca bu saatin GERCEKTEN ders oldugu bir gune yazilir. */
    private function assertOccurs(PrivateLessonSlot $slot, string $tarih): void
    {
        $gun = Carbon::parse($tarih);

        if ($gun->dayOfWeekIso !== $slot->weekday
            || $gun->lessThan($slot->starts_on)
            || ($slot->ends_on && $gun->greaterThan($slot->ends_on))) {
            throw ValidationException::withMessages(['date' => 'Bu tarihte bu saatte ders yok.']);
        }
    }
}
