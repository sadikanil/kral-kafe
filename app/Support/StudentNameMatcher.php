<?php

namespace App\Support;

use App\Models\ExamImportRow;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * PDF'teki adi kafedeki ogrenciye esler (1 Ekim 2026).
 *
 * Kurum PDF'inde ogrenci numarasi yok (Ö.No hep 0), yalnizca BUYUK HARFLI
 * ad ve sinif var; bazen soyadi yok, yalnizca ad. Bu yuzden:
 *   - Turkce harf duzeyinde normallestirilmis ad birebir ve tekse: AUTO
 *   - adin parcalari tek bir ogrencinin adinda (ya da tersi): SUGGESTED,
 *     yonetici kontrol eder
 *   - yoksa NONE: yonetici listeden secer ya da atlar
 * Ayni ogrenci iki satira eslenmez; ikinci satir kontrol beklemeye duser.
 */
final class StudentNameMatcher
{
    /** "İPEK ARSLAN" -> "ipek arslan"; "Işık" -> "isik". */
    public static function normalize(string $ad): string
    {
        $ad = strtr($ad, ['İ' => 'i', 'I' => 'ı']);
        $ad = mb_strtolower($ad, 'UTF-8');
        $ad = strtr($ad, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
        $ad = preg_replace('/[^a-z ]+/', ' ', $ad) ?? '';

        return trim(preg_replace('/\s+/', ' ', $ad) ?? '');
    }

    /**
     * Satirlara ogrenci ve eslesme turu yazar (yonetici secimine dokunmaz).
     *
     * @param  Collection<int,ExamImportRow>  $satirlar
     * @param  Collection<int,User>  $ogrenciler
     */
    public static function apply(Collection $satirlar, Collection $ogrenciler): void
    {
        $adlar = $ogrenciler->mapWithKeys(fn (User $o) => [$o->id => self::normalize((string) $o->name)]);
        $alinan = $satirlar->whereIn('match', [ExamImportRow::MANUAL, ExamImportRow::SKIP])
            ->pluck('student_id')->filter()->all();

        foreach ($satirlar as $satir) {
            if (in_array($satir->match, [ExamImportRow::MANUAL, ExamImportRow::SKIP], true)) {
                continue;
            }

            [$id, $tur] = self::find(self::normalize($satir->name), $adlar->all());

            if ($id !== null && in_array($id, $alinan, true)) {
                [$id, $tur] = [null, ExamImportRow::NONE];
            }
            if ($id !== null) {
                $alinan[] = $id;
            }

            $satir->fill(['student_id' => $id, 'match' => $tur])->save();
        }
    }

    /**
     * @param  array<int,string>  $adlar  ogrenci id => normallestirilmis ad
     * @return array{0:?int,1:string}
     */
    public static function find(string $aranan, array $adlar): array
    {
        if ($aranan === '') {
            return [null, ExamImportRow::NONE];
        }

        $birebir = array_keys($adlar, $aranan, true);
        if (count($birebir) === 1) {
            return [$birebir[0], ExamImportRow::AUTO];
        }
        if (count($birebir) > 1) {
            return [null, ExamImportRow::NONE];
        }

        $parcalar = explode(' ', $aranan);
        $kismi = array_keys(array_filter($adlar, function (string $ad) use ($parcalar) {
            $onunkiler = explode(' ', $ad);

            return array_diff($parcalar, $onunkiler) === [] || array_diff($onunkiler, $parcalar) === [];
        }));

        return count($kismi) === 1 ? [$kismi[0], ExamImportRow::SUGGESTED] : [null, ExamImportRow::NONE];
    }
}
