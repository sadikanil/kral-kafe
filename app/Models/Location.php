<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'qr_code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Self adisyon icin sanal lokasyonun QR kodu. Hicbir duvarda basili degil.
     */
    public const SELF_SERVICE_QR = 'SELF-ADISYON';

    /**
     * Konumu olmayan urunun tuketiminin baglandigi sanal lokasyon.
     *
     * consumptions.location_id NOT NULL ve butun raporlar/ekranlar
     * location->name okuyor; sutunu nullable yapmak yerine tek bir sistem
     * lokasyonu kullaniliyor. Konum etiketi listesine (tags()) ve sayima
     * girmez. Stok dusumu urunun kendisinden yapilir (Dalga 29).
     */
    public static function selfService(): self
    {
        return static::firstOrCreate(
            ['qr_code' => self::SELF_SERVICE_QR],
            [
                'name' => 'Self Adisyon',
                'type' => 'shelf',
                'description' => 'Sistem lokasyonu: öğrencinin panelden kendi eklediği tüketimler. Kapalı kalmalı; QR ile okutulmaz.',
                'is_active' => false,
            ]
        );
    }

    public function isSelfService(): bool
    {
        return $this->qr_code === self::SELF_SERVICE_QR;
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate QR code on creation
        static::creating(function ($location) {
            if (empty($location->qr_code)) {
                $location->qr_code = 'LOC-' . Str::upper(Str::random(8));
            }
        });
    }

    /**
     * Konum etiketleri (Dalga 29): sistem lokasyonu (self adisyon) haric,
     * ada gore. Lokasyonlar sayfasi kalkti; liste urun formunda ve stok
     * filtresinde.
     */
    public static function tags()
    {
        return static::where('qr_code', '!=', self::SELF_SERVICE_QR)->orderBy('name')->get();
    }

    /** Formda yazilan konum adi: varsa o etiket, yoksa yenisi. */
    public static function tagNamed(string $ad): self
    {
        return static::firstOrCreate(['name' => trim($ad)], ['type' => 'shelf', 'is_active' => true]);
    }

    /**
     * Bu konum etiketini tasiyan urunler (Dalga 29).
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
