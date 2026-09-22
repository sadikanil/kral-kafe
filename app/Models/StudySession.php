<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\SessionEndReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bir ogrencinin bir masadaki calisma oturumu.
 *
 * Sureler UTC saklanir (config/app.php timezone UTC ve oyle KALIR); gun/hafta
 * sinirlari App\Support\LocalDay uzerinden kafe saatine cevrilir.
 */
class StudySession extends Model
{
    protected $fillable = [
        'student_id',
        'study_table_id',
        'started_at',
        'ended_at',
        'duration_minutes',
        'end_reason',
        'approval_status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'end_reason' => SessionEndReason::class,
        'approval_status' => ApprovalStatus::class,
        'reviewed_at' => 'datetime',
    ];

    /**
     * Veritabani varsayilani modele yansimaz: create() sonrasi
     * $oturum->approval_status tazelenene kadar null doner ve "onaylandi mi"
     * sorusu sessizce yanlis cevaplanir. Ayni tuzak Dalga 2'de StudyTable'in
     * is_active'inde de ısırmıştı.
     */
    protected $attributes = [
        'approval_status' => ApprovalStatus::Pending->value,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(StudyTable::class, 'study_table_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Yoneticinin dogruladigi oturumlar.
     *
     * Toplamlara giren TEK durum. Acik oturum da buraya girmez: onay bitmis
     * bir surenin dogrulanmasi, devam eden bir sayacin degil. Ogrencinin o
     * anki sayaci panelde ayri bir canli kartta gorunur.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', ApprovalStatus::Approved->value);
    }

    /**
     * Yoneticinin bakmasi gereken oturumlar: bitmis ama karara baglanmamis.
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at')
            ->where('approval_status', ApprovalStatus::Pending->value);
    }

    /**
     * Bitmis ama toplamlara girmemis oturumlar: bekleyen + reddedilen.
     *
     * Ogrencinin kendi panelinde gordugu liste. Gormezse "iki saat calistim
     * ama panelde sifir yaziyor" durumu olusur ve ogrenci sisteme guvenmeyi
     * birakir; reddedilen oturum da sebebiyle birlikte burada gorunur.
     */
    public function scopeNotCredited(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at')
            ->whereIn('approval_status', [
                ApprovalStatus::Pending->value,
                ApprovalStatus::Rejected->value,
            ]);
    }

    /**
     * Istatistige giren oturumlar.
     *
     * Cok kisa oturum "yanlis okutma" sayilir: kayit SILINMEZ (veriyi yok etmek
     * denetimi imkansiz kilar) ama toplamlara katilmaz.
     *
     * Dalga 9'dan beri onay da bu tanimin parcasi: onaylanmamis bir sure
     * "istatistige giren" degildir. Tanimi tek yerde tutmak, veliye sizan bir
     * ekran kalmasini engelliyor.
     */
    public function scopeCountable(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at')
            ->approved()
            ->where('duration_minutes', '>=', config('kafe.sayilabilir_dakika'));
    }

    /**
     * Acik oturumun su ana kadarki suresi; kapali oturumda kayitli sure.
     */
    public function minutesSoFar(?Carbon $now = null): int
    {
        $bitis = $this->ended_at ?? ($now ?? now());

        return max(0, (int) $this->started_at->diffInMinutes($bitis));
    }
    /**
     * Oturumu kapatir - ama YALNIZCA hala acikken.
     *
     * Iki kapatma yolu var (ogrencinin elle bitirmesi ve otomatik kapanis) ve
     * ikisi de acik oturumu once OKUYUP sonra yaziyor. Arada gecen surede
     * digeri kapatmis olabilir; kosulsuz bir UPDATE o kapanisin uzerine yazar:
     *
     *   - otomatik kapanis manuel kapanisi ezerse sure kapanis saatine uzar,
     *   - manuel kapanis otomatigi ezerse ANOMALI kaydi silinir ve sure
     *     bitirme anina gore sisirilir.
     *
     * Ikisi de sessiz: hata yok, yalnizca yanlis sayi. Sart WHERE'e taşiniyor
     * ki karari veritabani versin.
     *
     * @return bool Kapatmayi bu cagri mi yapti (false: baskasi onceden kapatmis)
     */
    /**
     * Yonetici oturumu onaylar.
     *
     * Kosul WHERE'de, PHP'de degil - closeOnce ile ayni gerekce: karari
     * veritabani versin. Acik bir oturum onaylanamaz; onay, bitmis bir surenin
     * dogrulanmasidir.
     *
     * @return bool Yazma gerceklesti mi (false: oturum hala acik)
     */
    public function approve(User $reviewer): bool
    {
        return $this->review(ApprovalStatus::Approved, $reviewer, null);
    }

    /**
     * Yonetici oturumu reddeder. Sebep ZORUNLU.
     *
     * Kayit silinmez: ogrenci reddedilen oturumu sebebiyle birlikte gorur.
     * Sessizce silmek, ogrencinin suresinin neden kayboldugunu anlamasini
     * imkansiz kilardi.
     *
     * @return bool Yazma gerceklesti mi (false: sebep bos ya da oturum acik)
     */
    public function reject(User $reviewer, string $reason): bool
    {
        $sebep = trim($reason);

        if ($sebep === '') {
            return false;
        }

        return $this->review(ApprovalStatus::Rejected, $reviewer, $sebep);
    }

    /**
     * Birden cok oturumu tek yazmayla onaylar.
     *
     * Filtre SQL'de: yalnizca gercekten bekleyen ve bitmis oturumlar degisir.
     * Istemciden gelen id listesine guvenmek, kapali bir oturumu yeniden
     * "onayli" yazmak ya da baskasinin reddettigini sessizce geri almak
     * demekti.
     *
     * @param  array<int,int>  $ids
     * @return int Onaylanan oturum sayisi
     */
    public static function approveMany(array $ids, User $reviewer): int
    {
        if ($ids === []) {
            return 0;
        }

        return static::whereIn('id', $ids)
            ->awaitingApproval()
            ->update([
                'approval_status' => ApprovalStatus::Approved->value,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function review(ApprovalStatus $durum, User $reviewer, ?string $sebep): bool
    {
        $etkilenen = static::whereKey($this->getKey())
            ->whereNotNull('ended_at')
            ->update([
                'approval_status' => $durum->value,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $sebep,
                'updated_at' => now(),
            ]);

        $this->refresh();

        return $etkilenen === 1;
    }

    public function closeOnce(Carbon $endedAt, SessionEndReason $reason): bool
    {
        $etkilenen = static::whereKey($this->getKey())
            ->whereNull('ended_at')
            ->update([
                'ended_at' => $endedAt,
                'duration_minutes' => $this->minutesSoFar($endedAt),
                'end_reason' => $reason->value,
                'updated_at' => now(),
            ]);

        $this->refresh();

        return $etkilenen === 1;
    }
}
