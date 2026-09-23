<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dalga 30b: sinif + alan ve mufredattan gelen ders listesi.
 *
 * Dersler artik elle girilmiyor (Dersler sayfasi 30d'de kalkiyor); liste
 * MEB haftalik ders cizelgesi (TTKB 09/05/2025) ve 2026 YKS kilavuzundan.
 *
 *   subjects.code   - kararli anahtar (konu listesi buna baglanir)
 *   subjects.grades - virgullu sinif listesi; null = hepsi
 *   subjects.fields - virgullu alan listesi; null = hepsi
 *   exam_type       - tyt | ayt | ydt | okul
 *
 * Eski satirlar SILINMEZ: plan maddeleri, oturumlar ve deneme sonuclari
 * onlara bagli. Karsiligi olanlar yeni koda eslenir; TYT bolum satirlari
 * (Sosyal Bilimler, Fen Bilimleri) pasife alinir - yerlerini tek tek
 * dersler aldi.
 *
 * Veri adimlari yalnizca UPDATE/INSERT: --pretend hepsini SQL olarak
 * gosterir (README SS10.4).
 */
return new class extends Migration
{
    private const LISE_YKS = '11,12,mezun';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('grade', 10)->nullable();
            $table->string('field', 10)->nullable();
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique();
            $table->string('grades', 30)->nullable();
            $table->string('fields', 20)->nullable();
        });

        // Eski satirlar -> yeni kod ve ad.
        $esle = [
            ['tyt', 'Türkçe', 'tyt_turkce', 'TYT Türkçe'],
            ['tyt', 'Temel Matematik', 'tyt_matematik', 'TYT Matematik'],
            ['ayt', 'Türk Dili ve Edebiyatı', 'edebiyat', 'Edebiyat'],
            ['ayt', 'Tarih', 'tarih_1', 'Tarih-1'],
            ['ayt', 'Coğrafya', 'cografya_1', 'Coğrafya-1'],
            ['ayt', 'Matematik', 'ayt_matematik', 'AYT Matematik'],
            ['ayt', 'Fizik', 'ayt_fizik', 'AYT Fizik'],
            ['ayt', 'Kimya', 'ayt_kimya', 'AYT Kimya'],
            ['ayt', 'Biyoloji', 'ayt_biyoloji', 'AYT Biyoloji'],
        ];
        foreach ($esle as [$tur, $eski, $kod, $yeni]) {
            DB::table('subjects')->where('exam_type', $tur)->where('name', $eski)
                ->update(['code' => $kod, 'name' => $yeni]);
        }

        DB::table('subjects')->where('exam_type', 'tyt')
            ->whereIn('name', ['Sosyal Bilimler', 'Fen Bilimleri'])
            ->update(['is_active' => false]);

        $sira = 0;
        foreach ($this->dersler() as [$kod, $ad, $tur, $siniflar, $alanlar]) {
            $sira++;
            $alanlarVeSinif = ['grades' => $siniflar, 'fields' => $alanlar, 'sort_order' => $sira, 'is_active' => true];

            // Eslenmis satirin ozelliklerini de guncelle; yoksa ekle.
            DB::table('subjects')->where('code', $kod)->update($alanlarVeSinif);
            DB::table('subjects')->insertOrIgnore(array_merge($alanlarVeSinif, [
                'code' => $kod, 'name' => $ad, 'exam_type' => $tur,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }

    /**
     * @return list<array{0:string,1:string,2:string,3:?string,4:?string}>
     *         [kod, ad, sinav, siniflar, alanlar]
     */
    private function dersler(): array
    {
        $y = self::LISE_YKS;

        return [
            // TYT: herkes, her sinif.
            ['tyt_turkce', 'TYT Türkçe', 'tyt', null, null],
            ['tyt_matematik', 'TYT Matematik', 'tyt', null, null],
            ['geometri', 'Geometri', 'tyt', null, null],
            ['tyt_fizik', 'TYT Fizik', 'tyt', null, null],
            ['tyt_kimya', 'TYT Kimya', 'tyt', null, null],
            ['tyt_biyoloji', 'TYT Biyoloji', 'tyt', null, null],
            ['tyt_tarih', 'TYT Tarih', 'tyt', null, null],
            ['tyt_cografya', 'TYT Coğrafya', 'tyt', null, null],
            ['tyt_felsefe', 'TYT Felsefe', 'tyt', null, null],
            ['tyt_din', 'TYT Din Kültürü', 'tyt', null, null],
            // AYT: 11, 12, mezun; alana gore (2026 YKS kilavuzu, Tablo 1A-1E).
            ['ayt_matematik', 'AYT Matematik', 'ayt', $y, 'say,ea'],
            ['ayt_fizik', 'AYT Fizik', 'ayt', $y, 'say'],
            ['ayt_kimya', 'AYT Kimya', 'ayt', $y, 'say'],
            ['ayt_biyoloji', 'AYT Biyoloji', 'ayt', $y, 'say'],
            ['edebiyat', 'Edebiyat', 'ayt', $y, 'ea,soz'],
            ['tarih_1', 'Tarih-1', 'ayt', $y, 'ea,soz'],
            ['cografya_1', 'Coğrafya-1', 'ayt', $y, 'ea,soz'],
            ['tarih_2', 'Tarih-2', 'ayt', $y, 'soz'],
            ['cografya_2', 'Coğrafya-2', 'ayt', $y, 'soz'],
            ['felsefe_grubu', 'Felsefe Grubu', 'ayt', $y, 'soz'],
            ['ayt_din', 'AYT Din Kültürü', 'ayt', $y, 'soz'],
            ['ydt_ingilizce', 'YDT İngilizce', 'ydt', $y, 'dil'],
            // Okul dersleri (MEB 2025 cizelgesi). 11-12'de secmeliler AYT'nin
            // aynisi oldugu icin yalnizca ortak dersler.
            ['okul_edebiyat', 'Türk Dili ve Edebiyatı', 'okul', '9,10,11,12', null],
            ['okul_matematik', 'Matematik', 'okul', '9,10', null],
            ['okul_fizik', 'Fizik', 'okul', '9,10', null],
            ['okul_kimya', 'Kimya', 'okul', '9,10', null],
            ['okul_biyoloji', 'Biyoloji', 'okul', '9,10', null],
            ['okul_tarih', 'Tarih', 'okul', '9,10,11', null],
            ['okul_cografya', 'Coğrafya', 'okul', '9,10', null],
            ['okul_felsefe', 'Felsefe', 'okul', '10,11', null],
            ['okul_din', 'Din Kültürü ve Ahlak Bilgisi', 'okul', '9,10,11,12', null],
            ['okul_yabanci_dil', 'Yabancı Dil', 'okul', '9,10,11,12', null],
            ['okul_inkilap', 'T.C. İnkılap Tarihi ve Atatürkçülük', 'okul', '12', null],
        ];
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'grades', 'fields']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['grade', 'field']);
        });
    }
};
