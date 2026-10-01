<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Yuklenen dosyayi UPLOAD_DISK'e yazar; yazamazsa SEBEBIYLE hata atar.
 *
 * Neden (1 Ekim 2026): disklerde 'throw' => false. Laravel'in store()'u
 * yazamayinca sessizce false donuyordu; false dosya yolu olarak veritabanina
 * gidince Postgres reddediyor ve yonetici yalnizca "500" goruyordu - S3
 * anahtari mi yanlis, kova mi yok, disk mi salt-okunur, bilinemiyordu.
 * Burada Flysystem'in kendi istisnasi yakalanir ve kisa sebep yoneticiye
 * gosterilir (yalnizca yonetici ekranlari; sir icermez).
 */
final class Uploads
{
    /** @return string diskteki yol */
    public static function store(UploadedFile $dosya, string $klasor, ?string $ad = null): string
    {
        $diskAdi = (string) config('filesystems.uploads');
        $yol = trim($klasor, '/') . '/' . ($ad ?? $dosya->hashName());
        $akis = null;

        // Anahtar bossa AWS SDK sunucunun "instance profile"ina (169.254.169.254)
        // gider ve anlasilmaz bir cURL hatasi verir; once biz soyleriz.
        $ayar = (array) config("filesystems.disks.{$diskAdi}");
        if (($ayar['driver'] ?? null) === 's3') {
            $eksik = array_keys(array_filter(['AWS_ACCESS_KEY_ID' => $ayar['key'] ?? null, 'AWS_SECRET_ACCESS_KEY' => $ayar['secret'] ?? null,
                'AWS_BUCKET' => $ayar['bucket'] ?? null, 'AWS_ENDPOINT' => $ayar['endpoint'] ?? null, 'AWS_DEFAULT_REGION' => $ayar['region'] ?? null], 'blank'));

            if ($eksik !== []) {
                throw new RuntimeException("Dosya depolamaya yazılamadı ({$diskAdi}): Vercel'de tanımlı değil: " . implode(', ', $eksik) . '.');
            }
        }

        try {
            $akis = fopen($dosya->getRealPath(), 'r');
            // getDriver(): Flysystem'in kendisi; Laravel sarmalayicisinin
            // aksine hatayi yutmaz.
            Storage::disk($diskAdi)->getDriver()->writeStream($yol, $akis);
        } catch (\Throwable $e) {
            Log::error('Dosya yuklenemedi', ['disk' => $diskAdi, 'path' => $yol, 'error' => $e->getMessage(), 'cause' => $e->getPrevious()?->getMessage()]);

            throw new RuntimeException(self::sebep($diskAdi, $e), previous: $e);
        } finally {
            if (is_resource($akis)) {
                fclose($akis);
            }
        }

        return $yol;
    }

    private static function sebep(string $disk, \Throwable $e): string
    {
        $ipucu = '';

        if (config("filesystems.disks.{$disk}.driver") !== 's3' && filled(env('VERCEL'))) {
            $ipucu = " Vercel'de dosyalar yalnızca S3'e yazılabilir: UPLOAD_DISK=s3 olmalı.";
        }

        for ($k = $e; $k !== null; $k = $k->getPrevious()) {
            if (method_exists($k, 'getAwsErrorCode') && $k->getAwsErrorCode()) {
                return "Dosya depolamaya yazılamadı ({$disk}): {$k->getAwsErrorCode()} — " . self::kisalt((string) $k->getAwsErrorMessage())
                    . ' AWS_BUCKET, AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY ve AWS_ENDPOINT ayarlarını kontrol edin.';
            }
        }

        $kok = $e;
        while ($kok->getPrevious() !== null) {
            $kok = $kok->getPrevious();
        }

        return "Dosya depolamaya yazılamadı ({$disk}): " . self::kisalt($kok->getMessage()) . $ipucu;
    }

    private static function kisalt(string $metin): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $metin)), 0, 220, '…');
    }
}
