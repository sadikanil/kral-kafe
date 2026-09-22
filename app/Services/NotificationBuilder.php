<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\ExamEvent;
use App\Models\Notification;
use App\Models\StudySession;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;

/**
 * Gun sonu ve deneme oncesi bildirimlerini uretir (Dalga 11).
 *
 * Uretim cron'dan tetikleniyor. Tembel uretimle (Dalga 4'teki gibi)
 * cozulemezdi: bir oturumun kapanmasi veriden HESAPLANABILIR, ama bir
 * bildirim gonderilmis ya da gonderilmemistir - sonradan turetilemez.
 *
 * Teslim burada degil: bu sinif yalnizca KAYIT uretiyor. Bugun panel
 * okuyor; e-posta gelince ayni kayitlarin sent_at'ini dolduracak.
 */
class NotificationBuilder
{
    /**
     * O gun kafeye gelmeyen ogrencilerin velilerine bildirim.
     *
     * @return int Uretilen bildirim sayisi
     */
    public function absenceNotices(string $gun): int
    {
        [$gunBasi, $gunSonu] = LocalDay::bounds($gun);
        [$haftaBasi] = LocalDay::weekBounds($gun);

        $uretilen = 0;

        foreach ($this->ogrenciler() as $ogrenci) {
            // ONAY DURUMUNA BAKILMIYOR. Onay bir muhasebe karari; ogrencinin
            // kafede olup olmadigi ondan bagimsiz bir olgu. Onayli oturumlara
            // baksaydik, geldigi halde henuz onaylanmamis ogrencinin velisine
            // "gelmedi" bildirimi giderdi.
            $bugunGeldi = StudySession::where('student_id', $ogrenci->id)
                ->whereBetween('started_at', [$gunBasi, $gunSonu])
                ->exists();

            if ($bugunGeldi) {
                continue;
            }

            // O hafta hic gelmemisse susuyoruz: tatildeki ya da kaydi donmus
            // ogrencinin velisine her gun mesaj gitmesi gurultuye doner ve
            // gurultuye donen bildirim asil onemli olani da goturur.
            $haftaIcindeGeldi = StudySession::where('student_id', $ogrenci->id)
                ->whereBetween('started_at', [$haftaBasi, $gunSonu])
                ->exists();

            if (! $haftaIcindeGeldi) {
                continue;
            }

            foreach ($ogrenci->parents as $veli) {
                $uretilen += $this->kaydet(
                    NotificationType::Absence,
                    $veli,
                    $ogrenci,
                    null,
                    "absence:{$veli->id}:{$gun}",
                    "{$ogrenci->name} bugün kafeye gelmedi",
                    Carbon::parse($gun, LocalDay::timezone())->translatedFormat('d F Y') . ' tarihinde çalışma oturumu açılmadı.',
                ) ? 1 : 0;
            }
        }

        return $uretilen;
    }

    /**
     * Yarin denemesi olanlar icin ogrenciye ve velisine hatirlatma.
     *
     * @return int Uretilen bildirim sayisi
     */
    public function examReminders(string $gun): int
    {
        $yarin = Carbon::parse($gun, LocalDay::timezone())->addDay()->toDateString();

        $denemeler = ExamEvent::whereDate('exam_date', $yarin)->get();

        if ($denemeler->isEmpty()) {
            return 0;
        }

        $uretilen = 0;

        foreach ($denemeler as $deneme) {
            foreach ($this->ogrenciler() as $ogrenci) {
                $alicilar = collect([$ogrenci])->concat($ogrenci->parents);

                foreach ($alicilar as $alici) {
                    $uretilen += $this->kaydet(
                        NotificationType::ExamTomorrow,
                        $alici,
                        $ogrenci,
                        $deneme->id,
                        "exam:{$alici->id}:{$deneme->id}",
                        "Yarın deneme var: {$deneme->title}",
                        $deneme->starts_at ? "Başlangıç saati {$deneme->starts_at}." : null,
                    ) ? 1 : 0;
                }
            }
        }

        return $uretilen;
    }

    /**
     * Abonelikten bagimsiz TUM ogrenciler.
     *
     * Abonelik durumu erisim anahtari; devamsizlik ve deneme hatirlatmasi ise
     * ogrencinin kafeyle iliskisiyle ilgili. Pasif aboneligi olan ogrencinin
     * velisine bildirim gitmesi zaten istenmeyecegi icin filtre parents
     * bagina birakiliyor: bagli velisi olmayan ogrenci icin zaten bildirim
     * uretilmiyor.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    private function ogrenciler()
    {
        return User::where('role', Role::Student->value)
            ->with('parents')
            ->get();
    }

    /**
     * Bildirimi yazar; ayni anahtar zaten varsa hicbir sey yapmaz.
     *
     * @return bool Yeni kayit olustu mu
     */
    private function kaydet(
        NotificationType $tur,
        User $alici,
        ?User $ogrenci,
        ?int $ilgiliId,
        string $anahtar,
        string $baslik,
        ?string $govde,
    ): bool {
        if (Notification::where('unique_key', $anahtar)->exists()) {
            return false;
        }

        Notification::create([
            'type' => $tur->value,
            'user_id' => $alici->id,
            'student_id' => $ogrenci?->id,
            'related_id' => $ilgiliId,
            'unique_key' => $anahtar,
            'title' => $baslik,
            'body' => $govde,
        ]);

        return true;
    }
}
