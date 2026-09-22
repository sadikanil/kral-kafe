<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Standart TYT/AYT ders listesi (Dalga 12).
 *
 * Baslangic verisi, sabit liste DEGIL: yonetici panelden ekleyip cikarabilir.
 * Kuruma ozel bolumlemeler (ornegin "Matematik" yerine "Temel Matematik" +
 * "Geometri") bu yuzden mumkun.
 *
 * firstOrCreate: seeder birden cok kez calistirilabilir ve mevcut dersleri
 * ezmez - yoneticinin sildigi bir ders geri gelmez diye degil, adi ayni olan
 * kayit cogaltilmasin diye.
 */
class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $dersler = [
            ['tyt', 'Türkçe', 1],
            ['tyt', 'Sosyal Bilimler', 2],
            ['tyt', 'Temel Matematik', 3],
            ['tyt', 'Fen Bilimleri', 4],

            ['ayt', 'Türk Dili ve Edebiyatı', 1],
            ['ayt', 'Tarih', 2],
            ['ayt', 'Coğrafya', 3],
            ['ayt', 'Matematik', 4],
            ['ayt', 'Fizik', 5],
            ['ayt', 'Kimya', 6],
            ['ayt', 'Biyoloji', 7],
        ];

        foreach ($dersler as [$tur, $ad, $sira]) {
            Subject::firstOrCreate(
                ['exam_type' => $tur, 'name' => $ad],
                ['sort_order' => $sira],
            );
        }
    }
}
