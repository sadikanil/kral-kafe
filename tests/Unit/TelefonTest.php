<?php

namespace Tests\Unit;

use App\Support\Telefon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TelefonTest extends TestCase
{
    public static function gecerliler(): array
    {
        return [
            'bosluklu, sifirli' => ['0532 123 45 67', '5321234567'],
            'ulke kodlu' => ['+90 532 123 4567', '5321234567'],
            'ulke kodu 0090' => ['0090 532 123 45 67', '5321234567'],
            'tireli, parantezli' => ['(0532) 123-45-67', '5321234567'],
            'zaten duz' => ['5321234567', '5321234567'],
        ];
    }

    #[DataProvider('gecerliler')]
    public function test_a_mobile_number_is_reduced_to_ten_digits(string $girdi, string $beklenen): void
    {
        $this->assertSame($beklenen, Telefon::normalize($girdi));
    }

    public static function gecersizler(): array
    {
        return [
            'kisa' => ['532 123'],
            'uzun' => ['0532 123 45 678'],
            'sabit hat' => ['0212 123 45 67'],
            'harf' => ['abc'],
            'bos' => [''],
        ];
    }

    #[DataProvider('gecersizler')]
    public function test_anything_but_a_mobile_number_is_rejected(string $girdi): void
    {
        $this->assertNull(Telefon::normalize($girdi));
    }

    public function test_the_number_is_shown_in_the_familiar_form(): void
    {
        $this->assertSame('0532 123 45 67', Telefon::format('5321234567'));
    }
}
