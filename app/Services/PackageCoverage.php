<?php

namespace App\Services;

use App\Enums\PackagePeriod;
use App\Models\Consumption;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;

/**
 * Paket kapsaminin tuketime uygulanmasi (Dalga 8).
 *
 * Dalga 7'de package_items YALNIZCA TANIMDI: paketin neyi kapsadigi
 * yaziliydi ama hicbir yer okumuyordu, her tuketim tam fiyattan
 * faturalaniyordu. Burasi tanimi uyguluyor.
 *
 * Karar (SS7): limit asiminda ENGELLEME YOK, ucretlendir. Engellemek
 * ogrenciyi kasaya yonlendirirdi - self adisyonun butun amaci o degil.
 * Dolayisiyla kapsam bir bayrak degil bir SAYI: istenen adedin kaci
 * kapsanir sorusunu cevaplar, kalani ucretlenir.
 */
class PackageCoverage
{
    /**
     * Bu istekten kac adet paket kapsaminda kalir?
     *
     * Kapsam disiysa 0, sinirsizsa istenen adedin tamami.
     */
    public function coveredQuantity(User $student, Product $product, int $quantity, ?string $day = null): int
    {
        if ($quantity < 1) {
            return 0;
        }

        $kalem = $this->packageItem($student, $product);

        if ($kalem === null) {
            return 0;
        }

        if ($kalem->isUnlimited()) {
            return $quantity;
        }

        $kalan = max(0, (int) $kalem->included_quantity - $this->usedInPeriod(
            $student,
            $product,
            $kalem->period,
            $day ?? LocalDay::today(),
        ));

        return min($quantity, $kalan);
    }

    /**
     * Ogrencinin yururlukteki paketinde bu urunun kapsam kalemi.
     */
    private function packageItem(User $student, Product $product): ?PackageItem
    {
        $abonelik = $student->currentSubscription();

        if ($abonelik === null || $abonelik->package === null) {
            return null;
        }

        return $abonelik->package->items()
            ->where('product_id', $product->id)
            ->first();
    }

    /**
     * Bu donemde hakkindan kac adet KULLANILDI?
     *
     * Toplam covered_quantity uzerinden; quantity uzerinden sayilsaydi limit
     * asiminda UCRETI ODENEN adet de hakki tuketir ve ogrenci iki kez
     * cezalandirilmis olurdu.
     *
     * Geri alinan kayit sayilmaz - geri alma hakki da geri verir.
     */
    private function usedInPeriod(User $student, Product $product, PackagePeriod $period, string $day): int
    {
        [$bas, $son] = $this->window($period, $day);

        return (int) Consumption::query()
            ->where('user_id', $student->id)
            ->where('product_id', $product->id)
            ->where('is_undone', false)
            ->whereBetween('consumed_at', [$bas, $son])
            ->sum('covered_quantity');
    }

    /**
     * Donemin YEREL sinirlari, UTC Carbon olarak.
     *
     * LocalDay uzerinden gecmek sart: whereDate/whereMonth UTC gunune gore
     * calisiyor ve yerel 00:00-03:00 arasindaki tuketim bir onceki gune
     * duserdi - ogrenci gece yarisindan sonra gunluk hakkini iki kez
     * kullanirdi (SS10.1'deki tuzagin tuketim bicimi).
     *
     * @return array{0:Carbon,1:Carbon}
     */
    private function window(PackagePeriod $period, string $day): array
    {
        $gun = Carbon::parse($day, LocalDay::timezone());

        return match ($period) {
            PackagePeriod::Daily => LocalDay::bounds($day),
            PackagePeriod::Weekly => LocalDay::weekBounds($day),
            PackagePeriod::Monthly => LocalDay::monthBounds((int) $gun->year, (int) $gun->month),
        };
    }
}
