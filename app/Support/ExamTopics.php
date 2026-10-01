<?php

namespace App\Support;

/**
 * Deneme karnesindeki konu satirlari (1 Ekim 2026).
 *
 * Eksik konu KURALLA belirlenir, yapay zekayla degil (README SS6.1-6:
 * sistem sayi ve alan gosterir, sifat uretmez): soru sayisi 2+ ve basari
 * %50'nin altinda. Tek soruluk konuda tek yanlis "eksik" sayilmaz -
 * gurultu olurdu; 20 soruluk paragrafta 4 kayip (%80) da eksik degil.
 *
 * Satir: {subject, topic, questions, correct, wrong, blank}
 */
final class ExamTopics
{
    public static function isWeak(array $konu): bool
    {
        $soru = (int) ($konu['questions'] ?? 0);
        $dogru = (int) ($konu['correct'] ?? 0);

        if ($soru <= 0) {
            return false;
        }

        return $soru >= 2 && $dogru / $soru < 0.5;
    }

    /** Basari yuzdesi (tam sayi). */
    public static function success(array $konu): int
    {
        $soru = (int) ($konu['questions'] ?? 0);

        return $soru > 0 ? (int) round(100 * (int) ($konu['correct'] ?? 0) / $soru) : 0;
    }
}
