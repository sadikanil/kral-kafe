<?php

namespace App\Services;

use App\Enums\PauseKind;
use App\Enums\SessionEndReason;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Oturum acma/kapama kurallari tek yerde.
 *
 * Kurallar hem QR ekranindan hem ogrenci panelinden tetikleniyor; kontrolcuye
 * dagilirsa iki yol kacinilmaz olarak ayrisir.
 */
class StudySessionService
{
    public function openFor(User $student): ?StudySession
    {
        return StudySession::open()->where('student_id', $student->id)->first();
    }

    /**
     * Masada oturum baslatir.
     *
     * Ogrencinin baska masada acik oturumu varsa o kapatilir ve 'switched'
     * isaretlenir. Kapatma ile acma AYNI islemde: arada kalirsa ogrenci hem
     * oturumsuz kalir hem de kismi tekil indeks yuzunden yenisini acamaz.
     *
     * Ayni masada zaten acik oturum varsa hicbir sey yapilmaz ve mevcut oturum
     * doner - ogrenci butona iki kez basinca ya da mobilde POST yeniden
     * gonderilince ikinci istek hata gostermemeli.
     */
    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy?: float|null}  $location
     */
    public function start(User $student, StudyTable $table, array $location = []): StudySession
    {
        return DB::transaction(function () use ($student, $table, $location) {
            $acik = $this->openFor($student);

            if ($acik) {
                if ($acik->study_table_id === $table->id) {
                    return $acik;
                }

                $this->close($acik, SessionEndReason::Switched);
            }

            try {
                return StudySession::create([
                    'student_id' => $student->id,
                    'study_table_id' => $table->id,
                    'started_at' => now(),
                    'latitude' => $location['latitude'] ?? null,
                    'longitude' => $location['longitude'] ?? null,
                    'accuracy' => $location['accuracy'] ?? null,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Es zamanli ikinci istek kisiti yedi. Kaybeden istek de
                // kazananin oturumunu gostermeli, hata degil.
                $mevcut = $this->openFor($student);

                if ($mevcut === null) {
                    throw $e;
                }

                return $mevcut;
            }
        });
    }

    /**
     * Oturumu kapatir. Zaten kapaliysa DOKUNMAZ - bkz. StudySession::closeOnce().
     */
    public function close(StudySession $session, SessionEndReason $reason): StudySession
    {
        $session->closeOnce(now(), $reason);

        return $session;
    }

    /** Acik oturum yoksa sessizce gecer: bitirme butonu iki kez basilabilir. */
    public function endFor(User $student, SessionEndReason $reason = SessionEndReason::Manual): ?StudySession
    {
        $acik = $this->openFor($student);

        return $acik ? $this->close($acik, $reason) : null;
    }

    /**
     * Dalga 23: oturumu duraklatir. Zaten duraklamadaysa DOKUNMAZ - iki
     * sekmeden ya da cift dokunusla gelen ikinci istek yeni satir acmasin
     * (kismi tekil indeks son duvar).
     */
    public function pause(StudySession $session, PauseKind $kind): void
    {
        if ($session->ended_at !== null || $session->fresh('pauses')->openPause() !== null) {
            return;
        }

        try {
            $session->pauses()->create(['kind' => $kind, 'started_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            // Es zamanli ikinci istek: duraklama zaten acik, istenen de buydu.
        }

        $session->unsetRelation('pauses');
    }

    /** Acik duraklamayi kapatir; yoksa sessizce gecer. */
    public function resume(StudySession $session): void
    {
        $session->pauses()->whereNull('ended_at')->update(['ended_at' => now(), 'updated_at' => now()]);
        $session->unsetRelation('pauses');
    }
}
