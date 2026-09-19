<?php

namespace App\Services;

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
    public function start(User $student, StudyTable $table): StudySession
    {
        return DB::transaction(function () use ($student, $table) {
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

    public function close(StudySession $session, SessionEndReason $reason): StudySession
    {
        $bitis = now();

        $session->forceFill([
            'ended_at' => $bitis,
            'duration_minutes' => $session->minutesSoFar($bitis),
            'end_reason' => $reason,
        ])->save();

        return $session;
    }

    /** Acik oturum yoksa sessizce gecer: bitirme butonu iki kez basilabilir. */
    public function endFor(User $student, SessionEndReason $reason = SessionEndReason::Manual): ?StudySession
    {
        $acik = $this->openFor($student);

        return $acik ? $this->close($acik, $reason) : null;
    }
}
