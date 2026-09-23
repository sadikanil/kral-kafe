<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ogrenciye paket donemi acar (Dalga 7; Dalga 20'de ortak yere alindi).
 *
 * Iki yer kullaniyor: odeme ekranindan paket atama ve yeni ogrenci ekleme
 * akisi. Ayni kurali iki yerde tutmak, birinin gunun birinde fiyati
 * kopyalamayi unutmasi demekti.
 */
class SubscriptionOpener
{
    public function open(
        User $student,
        Package $package,
        Carbon $starts,
        ?Carbon $ends = null,
        ?string $price = null,
        ?string $note = null,
    ): Subscription {
        $ends ??= $starts->copy()->addMonthNoOverflow()->subDay();

        $abonelik = Subscription::create([
            'student_id' => $student->id,
            'package_id' => $package->id,
            'starts_on' => $starts->toDateString(),
            'ends_on' => $ends->toDateString(),
            // Fiyat KOPYALANIR: katalog sonradan degisse de bu satir sabit.
            'price' => $price ?? $package->monthly_price,
            'note' => $note,
            'created_by' => auth()->id(),
        ]);
        $abonelik->syncPaymentStatus();

        // Ek paket ana paketin tarihlerini ezmesin: abonelik kapisi
        // users.subscription_status'a bakiyor, tarihler ana paketten gelir.
        if (! $package->is_addon) {
            $student->update([
                'subscription_status' => 'active',
                'subscription_start' => $starts->toDateString(),
                'subscription_end' => $ends->toDateString(),
            ]);
        }

        return $abonelik;
    }
}
