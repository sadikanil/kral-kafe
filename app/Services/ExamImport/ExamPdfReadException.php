<?php

namespace App\Services\ExamImport;

/** Okuma basarisiz; mesaj yoneticiye gosterilir. */
class ExamPdfReadException extends \RuntimeException
{
    /**
     * Zaman asimi mesajinin isareti (5 Ekim 2026). Kayitli hata metninde bu
     * gecerse adim yeniden denenebilir; model bazen bir karnede 50 saniyeyi
     * asiyor, ikinci deneme genelde sigiyor.
     */
    public const TIMEOUT_MARK = 'zaman aşımı';

    /**
     * Gecici hata mi (zaman asimi, kota, 5xx, baglanti)? Gecici hata aktarimi
     * durdurmaz; adim kendiliginden tekrarlanir (ExamImportProcessor).
     * $pause: tekrar oncesi beklenecek saniye (kota dolunca hemen denemek
     * ayni hatayi alir).
     */
    public bool $transient = false;

    public int $pause = 0;

    public static function transient(string $mesaj, ?\Throwable $onceki = null, int $bekle = 0): self
    {
        $e = new self($mesaj, previous: $onceki);
        $e->transient = true;
        $e->pause = $bekle;

        return $e;
    }

    public static function timeout(?\Throwable $onceki = null): self
    {
        return self::transient('Sayfa 50 saniyede okunamadı (' . self::TIMEOUT_MARK . ').', $onceki);
    }

    /** Kota/yogunluk (429) ya da sunucu hatasi (5xx): gecici. */
    public static function forStatus(int $durum, string $mesaj): self
    {
        return match (true) {
            $durum === 429 => self::transient($mesaj, bekle: 15),
            $durum >= 500 => self::transient($mesaj, bekle: 5),
            default => new self($mesaj),
        };
    }

    /** cURL 28 / "timed out": Http istemcisinin zaman asimi. */
    public static function isTimeout(\Throwable $e): bool
    {
        $metin = $e->getMessage();

        return str_contains($metin, 'cURL error 28') || stripos($metin, 'timed out') !== false;
    }

    public static function isRetryable(?string $hata): bool
    {
        return $hata !== null && str_contains($hata, self::TIMEOUT_MARK);
    }
}
