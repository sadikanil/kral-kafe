<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Paketlerin aylik yenilenmesi (18-21 Kasim 2026).
 *
 * Isletme karari: paket iptal edilmedikce ya da degistirilmedikce her ay
 * tekrar eder ve ayin BASINDA yenilenir. auto_renew acik ve bitmis bir
 * donem, bitisinin ertesi gunu ayni paketle ve ayni AYLIK tutarla (indirim
 * surer) yeni donem acar; donem o ayin sonunda biter. Takvim ayina oturmus
 * donem hep ayin 1'inde yenilenir; eski (ay ortasinda biten) donemin
 * yenilemesi ay sonuna kadar kist bir kopru olur, sonrasi ayin 1'i.
 * "Sonraki donemden" paket degisikligi varsa o paket ve tutar gecerli olur.
 *
 * Uc yerden calisir, hepsi ayni sonucu verir (idempotan):
 * - gece isi (00:05 yerel; CronController::nightly),
 * - uyenin kendi istegi: haklar (masa, deneme kulubu) sabah cron'u
 *   beklemeden yeni donemden okunur (User::entitlements),
 * - yonetimin paket ve odeme sayfalari.
 *
 * Bir donem yalniz bir kez yenilenir: satir kilitlenir ve renewed_from_id
 * benzersizdir. Gece isi gunlerce calismadiysa kacan aylar sirayla acilir.
 *
 * Yenilenmeyen durumlar (donemin auto_renew'u kapanir, yonetici gorur):
 * - uye aktif degil (askiya alindi/kapatildi): gecmis aylari borc yazmak
 *   yerine zincir durur; geri gelince paket yeniden atanir,
 * - ayni yuvada daha yeni bir donem var (zincir oradan surer).
 */
class SubscriptionRenewer
{
    /** Kacan aylari yakalama siniri: iki yil. Sonsuz donguye karsi da sigorta. */
    private const AZAMI_TUR = 24;

    public function __construct(private SubscriptionOpener $acici)
    {
    }

    /** @return int acilan donem sayisi */
    public function renewDue(?string $today = null): int
    {
        return $this->calistir(fn () => $this->vadesiGelen($today), $today);
    }

    /** @return int acilan donem sayisi */
    public function renewForStudent(User $student, ?string $today = null): int
    {
        return $this->calistir(fn () => $this->vadesiGelen($today)->where('student_id', $student->id), $today);
    }

    private function calistir(\Closure $sorgu, ?string $today): int
    {
        $gun = $today ?? LocalDay::today();
        $acilan = 0;

        // Her tur bir ay ilerler: yeni acilan donem de bitmisse sonraki tur
        // onu yakalar. Yenilenmeyen donemin auto_renew'u kapandigi icin ayni
        // satir tekrar gelmez.
        for ($tur = 0; $tur < self::AZAMI_TUR; $tur++) {
            $donemler = $sorgu()->with(['student', 'package', 'nextPackage'])->get();

            if ($donemler->isEmpty()) {
                break;
            }

            foreach ($donemler as $donem) {
                $acilan += $this->yenile($donem, $gun) ? 1 : 0;
            }
        }

        return $acilan;
    }

    private function vadesiGelen(?string $today): Builder
    {
        return Subscription::query()
            ->where('auto_renew', true)
            ->where('payment_status', '!=', PaymentStatus::Cancelled->value)
            ->where('ends_on', '<', $today ?? LocalDay::today())
            ->whereDoesntHave('renewal')
            ->orderBy('ends_on')
            ->orderBy('id');
    }

    private function yenile(Subscription $donem, string $gun): bool
    {
        try {
            return DB::transaction(function () use ($donem, $gun) {
                // Taze oku ve kilitle: gece isi ile bir sayfa acilisi ayni
                // donemi ayni anda yenilemesin (Postgres; SQLite zaten sirali).
                $donem = Subscription::whereKey($donem->id)->lockForUpdate()->first()?->load(['student', 'package', 'nextPackage']);

                if ($donem === null
                    || ! $donem->auto_renew
                    || $donem->isCancelled()
                    || $donem->ends_on->toDateString() >= $gun
                    || $donem->renewal()->exists()) {
                    return false;
                }

                $uye = $donem->student;
                if ($uye === null || ! $uye->isStudent() || ! $uye->hasActiveSubscription() || $donem->isSuperseded()) {
                    $donem->forceFill(['auto_renew' => false])->save();

                    return false;
                }

                [$paket, $tutar] = $donem->nextTerms();

                $this->acici->open(
                    $uye,
                    $paket,
                    $donem->renewsOn(),
                    null,
                    $tutar,
                    'Otomatik yenileme',
                    autoRenew: true,
                    renewedFrom: $donem,
                );

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Baska bir istek ayni donemi az once yeniledi.
            return false;
        }
    }
}
