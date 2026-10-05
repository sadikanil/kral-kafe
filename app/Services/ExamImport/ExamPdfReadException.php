<?php

namespace App\Services\ExamImport;

/** Okuma basarisiz; mesaj yoneticiye gosterilir. */
class ExamPdfReadException extends \RuntimeException
{
    /**
     * Zaman asimi mesajinin isareti (5 Ekim 2026). Kayitli hata metninde bu
     * gecerse okuma sayfasi ayni adimi kendiliginden yeniden dener; model
     * bazen bir karnede 50 saniyeyi asiyor, ikinci deneme genelde sigiyor.
     */
    public const TIMEOUT_MARK = 'zaman aşımı';

    public static function timeout(?\Throwable $onceki = null): self
    {
        return new self('Sayfa 50 saniyede okunamadı (' . self::TIMEOUT_MARK . '); "Devam et" ile yeniden deneyin.', previous: $onceki);
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
