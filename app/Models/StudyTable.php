<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Calisma masasi.
 *
 * qr_code BIR DAHA DEGISMEZ. Etiketler basilip duvara yapistiriliyor; kod
 * yenilenirse hata ekranda gorunmez, kafedeki fiziksel etiketler sessizce olur.
 * Bu yuzden alan fillable DEGIL ve yalnizca olusturulurken uretilir.
 */
class StudyTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Veritabani varsayilani modele yansimaz: create() sonrasi $masa->is_active
     * tazelenene kadar null doner ve "masa acik mi" sorusu sessizce yanlis
     * cevaplanir. Varsayilani burada da soyluyoruz.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (StudyTable $masa) {
            $masa->qr_code ??= 'MASA-' . Str::upper(Str::random(8));
        });
    }

    /**
     * Etikete basilan adres. Rota Dalga 3'te geliyor; yol bicimi buradaki tek
     * tanimdan okunur ki basilmis etiketlerle kod arasinda ayrisma olmasin.
     */
    public function getQrUrlAttribute(): string
    {
        return url("/masa/{$this->qr_code}");
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(StudySession::class);
    }

    /**
     * Doluluk: bir masa kaydi bir oturma yeri (QR sandalye basina). Canli
     * ekran ve yonetim paneli ayni sayiyi gostersin diye tek yerde.
     * Kapatilmis masada unutulan oturum bos yeri eksiye dusurmez.
     *
     * @return array{total: int, inside: int, free: int}
     */
    public static function occupancy(): array
    {
        $toplam = self::active()->count();
        $iceride = StudySession::open()->count();

        return ['total' => $toplam, 'inside' => $iceride, 'free' => max(0, $toplam - $iceride)];
    }

    /** "Masa 2" "Masa 10"dan once; duz isim sirasi bunu tersine cevirir. */
    public static function sortedByNumber(Builder $query): Collection
    {
        return $query->get()->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
