<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 19: bir kullanicinin paketlerinden dogan haklari.
 *
 * Haklar paketin BAYRAKLARINDAN gelir, tier numarasindan degil: tier
 * yalnizca etiket. Ek paket (deneme kulubu) ana paketle birlesir.
 */
class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private function abone(User $ogrenci, Package $paket, array $ek = []): Subscription
    {
        return Subscription::factory()->create(array_merge([
            'student_id' => $ogrenci->id,
            'package_id' => $paket->id,
        ], $ek));
    }

    public function test_a_student_without_a_package_has_nothing(): void
    {
        $hak = User::factory()->student()->create()->entitlements();

        $this->assertFalse($hak->table);
        $this->assertFalse($hak->coaching);
        $this->assertFalse($hak->examClub);
        $this->assertFalse($hak->privateLessons);
    }

    public function test_tier_one_gives_only_the_table(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->abone($ogrenci, Package::factory()->tier1()->create());

        $hak = $ogrenci->entitlements();

        $this->assertTrue($hak->table);
        $this->assertFalse($hak->coaching);
        $this->assertFalse($hak->examClub);
    }

    public function test_an_exam_club_addon_joins_the_main_package(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->abone($ogrenci, Package::factory()->tier2()->create());
        $this->abone($ogrenci, Package::factory()->examClubAddon()->create());

        $hak = $ogrenci->entitlements();

        $this->assertTrue($hak->table);
        $this->assertTrue($hak->coaching);
        $this->assertTrue($hak->examClub);
        $this->assertFalse($hak->privateLessons);
    }

    public function test_tier_three_gives_everything(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->abone($ogrenci, Package::factory()->tier3()->create());

        $hak = $ogrenci->entitlements();

        $this->assertTrue($hak->table && $hak->coaching && $hak->examClub && $hak->privateLessons);
    }

    public function test_an_exam_only_package_has_no_table(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->abone($ogrenci, Package::factory()->examOnly()->create());

        $hak = $ogrenci->entitlements();

        $this->assertFalse($hak->table);
        $this->assertTrue($hak->examClub);
    }

    public function test_an_expired_subscription_gives_nothing(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->abone($ogrenci, Package::factory()->tier3()->create(), [
            'starts_on' => now()->subMonths(2)->toDateString(),
            'ends_on' => now()->subMonth()->toDateString(),
        ]);

        $this->assertFalse($ogrenci->entitlements()->table);
    }

    public function test_a_cancelled_subscription_gives_nothing(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->abone($ogrenci, Package::factory()->tier3()->create(), [
            'payment_status' => PaymentStatus::Cancelled->value,
        ]);

        $this->assertFalse($ogrenci->entitlements()->table);
    }

    /** Veli ayri etiket tasimaz; cocugunun paketini gorur (karar, 23 Eyl). */
    public function test_a_parent_has_what_their_children_have(): void
    {
        $veli = User::factory()->parent()->create();
        $birinci = User::factory()->student()->create();
        $ikinci = User::factory()->student()->create();
        $veli->students()->attach([$birinci->id, $ikinci->id]);
        $this->abone($birinci, Package::factory()->tier1()->create());
        $this->abone($ikinci, Package::factory()->examOnly()->create());

        $hak = $veli->entitlements();

        $this->assertTrue($hak->table);
        $this->assertTrue($hak->examClub);
        $this->assertFalse($hak->coaching);
    }

    public function test_a_coach_is_not_limited_by_packages(): void
    {
        $this->assertTrue(User::factory()->create(['role' => 'coach'])->entitlements()->examClub);
    }

    public function test_the_admin_has_everything(): void
    {
        $hak = User::factory()->admin()->create()->entitlements();

        $this->assertTrue($hak->table && $hak->coaching && $hak->examClub && $hak->privateLessons);
    }

    /** Panelde "paketin" rozeti ana paketi gostermeli, eki degil. */
    public function test_the_current_subscription_is_the_main_package_not_the_addon(): void
    {
        $ogrenci = User::factory()->student()->create();
        $ana = $this->abone($ogrenci, Package::factory()->tier2()->create());
        $this->abone($ogrenci, Package::factory()->examClubAddon()->create(), [
            'starts_on' => now()->toDateString(),
        ]);

        $this->assertTrue($ana->is($ogrenci->currentSubscription()));
    }
}
