<?php

namespace App\Support;

/**
 * Deger dizisini SVG polyline koordinatlarina cevirir (Dalga 12b).
 *
 * Sunucuda uretiliyor: proje derleme adimi tasimiyor ve bir grafik
 * kutuphanesini CDN'den cekmek yeni bir bagimlilik sinifi sokardi. Ayrica
 * burada uretmek grafigi TEST EDILEBILIR kiliyor.
 */
class ChartPath
{
    /**
     * "x,y x,y ..." dizgesi. Bos noktalar ATLANIR.
     *
     * Eksik noktayi sifir saymak "sifir cekti" demek olurdu - girilmemis
     * veriyle kotu sonucu ayirt edememek grafigi yalanci yapar. Cizgi var
     * olan noktalari birlestirir, bosluk uzerinden gecer.
     *
     * @param  list<float|null>  $values
     */
    public static function points(array $values, float $min, float $max, int $width, int $height): string
    {
        $adet = count($values);

        if ($adet === 0) {
            return '';
        }

        // Duz seri ortada durur; aksi halde (max - min) sifira bolme olurdu.
        $aralik = $max - $min;
        $adim = $adet > 1 ? $width / ($adet - 1) : 0;

        $koordinatlar = [];

        foreach ($values as $sira => $deger) {
            if ($deger === null) {
                continue;
            }

            $x = (int) round($sira * $adim);
            $y = $aralik > 0
                ? (int) round($height - (($deger - $min) / $aralik) * $height)
                : (int) round($height / 2);

            $koordinatlar[] = "{$x},{$y}";
        }

        return implode(' ', $koordinatlar);
    }
}
