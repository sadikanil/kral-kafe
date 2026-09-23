<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;

/**
 * Panelden degistirilebilen ayarlar (anahtar/deger).
 *
 * config/kafe.php'den farki: orasi DEPLOY ile degisen sabitler (kafe saatleri,
 * esikler), burasi yoneticinin calisirken degistirdikleri. Kafe koordinati
 * kafede olculerek aliniyor, dolayisiyla buraya ait.
 */
class Setting extends Model
{
    use HasFactory;

    public const KAFE_KONUM = 'kafe_konum';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    /**
     * Kafenin koordinati; hic girilmediyse null.
     *
     * Tip kontrolu bilerek var: sutun JSON ve elle duzenlenmis ya da yarim
     * kalmis bir kayit mesafe hesabini sessizce bozardi. Eksikse "konum
     * ayarlanmamis" demek, uydurma bir koordinatla hesap yapmaktan iyidir.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function cafeLocation(): ?array
    {
        // Istek basina bir kez (P9): canli ekran her bekleyen oturum icin
        // mesafeyi iki kez soruyor, kuyruk uzadikca ayni satir 2N kez
        // okunuyordu. once() testler arasinda da temizlenir; yazma aninda
        // booted() temizler ki ayni istekte kaydedilen konum gorulsun.
        return once(fn () => static::readCafeLocation());
    }

    protected static function booted(): void
    {
        static::saved(fn () => Once::flush());
        static::deleted(fn () => Once::flush());
    }

    /** @return array{lat: float, lng: float}|null */
    private static function readCafeLocation(): ?array
    {
        $deger = static::where('key', self::KAFE_KONUM)->value('value');

        if (! is_array($deger) || ! isset($deger['lat'], $deger['lng'])) {
            return null;
        }

        return ['lat' => (float) $deger['lat'], 'lng' => (float) $deger['lng']];
    }

    public static function putCafeLocation(float $lat, float $lng): void
    {
        static::updateOrCreate(
            ['key' => self::KAFE_KONUM],
            ['value' => ['lat' => $lat, 'lng' => $lng]],
        );
    }
}
