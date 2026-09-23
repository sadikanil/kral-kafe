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
     * Stok sayimi hatirlatmasi (Dalga 27).
     *
     * Son sayimdan N gun (kafe.stok_sayim_gun) gectiyse her yoneticiye. Sayim
     * yapilmazsa HER GUN degil her N gunde bir: anahtar gunu degil "kacinci
     * N'lik dilim"i tasir. Tam gunu tutturmaya dayanmaz - Hobby cron'u bir
     * gun kacirirsa hatirlatma ertesi gun yine gider.
     */
    public function stockCountReminders(string $gun): int
    {
        $aralik = max(1, (int) config('kafe.stok_sayim_gun'));
        $sonAn = \App\Models\StockRecord::max('recorded_at');
        $son = $sonAn ? LocalDay::of(Carbon::parse($sonAn)) : null;

        // Hic sayim yoksa dilim takvimden sayilir (yine N gunde bir).
        $gecen = (int) Carbon::parse($son ?? '1970-01-01')->diffInDays(Carbon::parse($gun), false);

        if ($son !== null && $gecen < $aralik) {
            return 0;
        }

        $dilim = intdiv($gecen, $aralik);
        $govde = $son === null
            ? 'Sistemde henüz stok sayımı yok.'
            : "Son sayım {$gecen} gün önce (" . Carbon::parse($son)->format('d.m.Y') . ').';

        $yeni = 0;
        foreach (User::where('role', Role::Admin->value)->get() as $yonetici) {
            $yeni += (int) $this->kaydet(
                NotificationType::StockCount,
                $yonetici,
                null,
                null,
                "stock_count:{$yonetici->id}:" . ($son ?? 'yok') . ":{$dilim}",
                'Stok sayımı zamanı 📦',
                $govde,
            );
        }

        return $yeni;
    }

    /**
     * Kritik stok uyarisi (Dalga 29): her yoneticiye. Ne zaman cagrilacagina
     * Product karar verir (sinir gecildiginde); burada tekrar kontrolu yok.
     * Anahtar ani tasir - ayni urun ikinci kez kritige inerse ikinci uyari.
     */
    public function lowStock(\App\Models\Product $urun): int
    {
        $kalan = $urun->stock_quantity <= 0
            ? 'Stok tükendi'
            : "{$urun->stock_quantity} {$urun->unit_type} kaldı";
        $govde = "{$kalan} (kritik: {$urun->critical_quantity})."
            . ($urun->location ? " Konum: {$urun->location->name}." : '');
        $an = now()->format('YmdHisv') . \Illuminate\Support\Str::random(4);

        $yeni = 0;
        foreach (User::where('role', Role::Admin->value)->get() as $yonetici) {
            $yeni += (int) $this->kaydet(
                NotificationType::LowStock,
                $yonetici,
                null,
                $urun->id,
                "low_stock:{$yonetici->id}:{$urun->id}:{$an}",
                "Kritik stok: {$urun->name} ⚠️",
                $govde,
            );
        }

        return $yeni;
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
