<?php

namespace App\Services\ExamImport;

/**
 * Iki saglayicinin ortak istemi ve JSON semasi (1 Ekim 2026).
 *
 * Sema her iki API'nin kati moduna uyar: her nesnede additionalProperties
 * false, her alan required, bos olabilen alan anyOf [..., null]. Sayisal
 * sinir (minimum vb.) YOK: Claude yapilandirilmis cikti desteklemiyor;
 * dogrulama PHP'de (ExamImportProcessor).
 *
 * Ders kodlari denemenin turune gore verilir (TYT'de "Tarih-1" TYT Tarih,
 * AYT'de Tarih-1); karsiligi olmayan ders (ornegin "Felsefe (Seçmeli)")
 * "other" olur ve sonuca yazilmaz.
 */
final class ExamPdfSchema
{
    public static function system(): string
    {
        return 'Sen bir deneme sınavı sonuç belgesi okuyucususun. Görevin belgedeki tablolardaki sayıları ve '
            . 'adları olduğu gibi aktarmak. Yalnızca belgede yazanı kullan; tahmin etme, hesaplama yapma, '
            . 'olmayanı uydurma; okunamayan ya da belgede olmayan alanı null bırak. Öğrenciler hakkında yorum '
            . 'yapma, sıfat kullanma. Adları belgede yazdığı gibi (büyük harf, Türkçe karakterlerle) yaz.';
    }

    /** @param array<string,string> $dersler */
    public static function indexPrompt(array $dersler): string
    {
        return "Bu PDF bir kurumun deneme sınavı sonuç belgesi: önce okul/sınıf net listeleri, ardından öğrenci başına "
            . "birer sayfalık karneler olabilir.\n\n"
            . "1. Sınavın adını ve (varsa) tarihini yaz.\n"
            . "2. Katılımcı sayılarını yaz: kurum (okul), ilçe, il, genel (Türkiye).\n"
            . "3. Okul net listesindeki HER öğrenciyi bir kez listele: ad, sınıf, puan, varsa karnesinin sayfa "
            . "numarası (1'den başlar; karne yoksa null) ve listede ders ders doğru/yanlış sayıları.\n\n"
            . "Ders kodları (belgedeki dersi en uygun koda eşle; karşılığı yoksa \"other\"):\n"
            . self::kodListesi($dersler);
    }

    /** @param array<string,string> $dersler */
    public static function cardPrompt(int $sayfa, string $ad, array $dersler): string
    {
        return "Yalnızca {$sayfa}. sayfadaki öğrenci karnesini oku (beklenen öğrenci: {$ad}). Başka sayfalardaki "
            . "bilgileri karıştırma.\n\n"
            . "1. Ad, sınıf ve puan.\n"
            . "2. Sıralar: şube, kurum, ilçe, il, genel için öğrencinin sırası ve o düzeyde sınava katılan öğrenci "
            . "sayısı (\"Öğrenci Sıra No\" ve \"Sınava Katılan Öğrenci Sayısı\" satırları).\n"
            . "3. Ders tablosu: her ders için soru, doğru, yanlış, boş. Bölüm toplamlarını (\"TYT Sosyal\", "
            . "\"TYT Fen\", \"TYT Matematik\" gibi alt derslerin toplamı) ayrıca yazma; alt dersleri yaz.\n"
            . "4. Konu tablosu: her konu satırı için dersin kodu, konu adı, soru (S), doğru (D), yanlış (Y). "
            . "Ders başlık satırlarını (\"Türkçe 0 0 0 0\" gibi) konu sayma.\n\n"
            . "Ders kodları (karşılığı yoksa \"other\"):\n"
            . self::kodListesi($dersler);
    }

    /** @param array<string,string> $dersler */
    public static function index(array $dersler): array
    {
        return self::nesne([
            'exam' => self::nesne([
                'name' => self::bos('string'),
                'date' => self::bos('string'),
            ]),
            'participants' => self::nesne([
                'institution' => self::bos('integer'),
                'district' => self::bos('integer'),
                'city' => self::bos('integer'),
                'country' => self::bos('integer'),
            ]),
            'students' => ['type' => 'array', 'items' => self::nesne([
                'name' => ['type' => 'string'],
                'class' => self::bos('string'),
                'card_page' => self::bos('integer'),
                'score' => self::bos('number'),
                'subjects' => ['type' => 'array', 'items' => self::nesne([
                    'code' => self::kod($dersler),
                    'label' => ['type' => 'string'],
                    'correct' => ['type' => 'integer'],
                    'wrong' => ['type' => 'integer'],
                ])],
            ])],
        ]);
    }

    /** @param array<string,string> $dersler */
    public static function card(array $dersler): array
    {
        $sira = self::nesne(['rank' => self::bos('integer'), 'total' => self::bos('integer')]);

        return self::nesne([
            'name' => ['type' => 'string'],
            'class' => self::bos('string'),
            'score' => self::bos('number'),
            'ranks' => self::nesne([
                'branch' => $sira,
                'institution' => $sira,
                'district' => $sira,
                'city' => $sira,
                'country' => $sira,
            ]),
            'subjects' => ['type' => 'array', 'items' => self::nesne([
                'code' => self::kod($dersler),
                'label' => ['type' => 'string'],
                'questions' => self::bos('integer'),
                'correct' => ['type' => 'integer'],
                'wrong' => ['type' => 'integer'],
                'blank' => self::bos('integer'),
            ])],
            'topics' => ['type' => 'array', 'items' => self::nesne([
                'subject_code' => self::kod($dersler),
                'topic' => ['type' => 'string'],
                'questions' => ['type' => 'integer'],
                'correct' => ['type' => 'integer'],
                'wrong' => ['type' => 'integer'],
            ])],
        ]);
    }

    /** @param array<string,string> $dersler */
    private static function kodListesi(array $dersler): string
    {
        $liste = collect($dersler)->map(fn ($ad, $kod) => "- {$kod}: {$ad}")->implode("\n") . "\n- other: diğer / karşılığı yok";

        // Kurum karnelerinde TYT ders adlari kodlarla birebir degil; bolum
        // toplami ("TYT Matematik" = Matematik-1 + Geometri) koda karisirsa
        // net iki kez sayilir.
        if (isset($dersler['tyt_matematik'])) {
            $liste .= "\n\nTYT eşlemesi: \"Türkçe\" → tyt_turkce; \"Matematik-1\" (ya da \"Temel Matematik\") → tyt_matematik; "
                . "\"Geometri\" → geometri; \"Tarih-1\" → tyt_tarih; \"Coğrafya-1\" → tyt_cografya; \"Felsefe\" → tyt_felsefe; "
                . "\"Din Kül. ve Ahl. Bil.\" → tyt_din; \"Felsefe (Seçmeli)\" → other. "
                . "\"TYT Matematik\", \"TYT Sosyal\", \"TYT Fen\" ve \"Toplam\" satırları bölüm toplamıdır; ders olarak yazma.";
        }

        return $liste;
    }

    /** @param array<string,string> $dersler */
    private static function kod(array $dersler): array
    {
        return ['type' => 'string', 'enum' => [...array_keys($dersler), 'other']];
    }

    private static function bos(string $tur): array
    {
        return ['anyOf' => [['type' => $tur], ['type' => 'null']]];
    }

    /** @param array<string,array<string,mixed>> $alanlar */
    private static function nesne(array $alanlar): array
    {
        return [
            'type' => 'object',
            'properties' => $alanlar,
            'required' => array_keys($alanlar),
            'additionalProperties' => false,
        ];
    }
}
