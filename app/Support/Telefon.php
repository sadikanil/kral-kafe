<?php

namespace App\Support;

/**
 * Cep telefonu numarasi (Dalga 18).
 *
 * Giris kimligi oldugu icin TEK bicimde saklanir: 10 hane, 5 ile baslar
 * ("5321234567"). Yonetici "0532 123 45 67", "+90 532..." ya da tireli
 * yazabilir; hepsi ayni kayda denk gelmeli, yoksa tekillik kurali ayni
 * numarayi iki kez kabul ederdi.
 */
final class Telefon
{
    public static function normalize(string $girdi): ?string
    {
        $rakamlar = preg_replace('/\D/', '', $girdi);

        // Ulke kodu (0090 / 90) ve sehir-ici sifiri at.
        $rakamlar = preg_replace('/^(0090|90|0)(?=5\d{9}$)/', '', $rakamlar);

        return preg_match('/^5\d{9}$/', $rakamlar) ? $rakamlar : null;
    }

    public static function format(string $telefon): string
    {
        return preg_replace('/^(\d{3})(\d{3})(\d{2})(\d{2})$/', '0$1 $2 $3 $4', $telefon);
    }
}
