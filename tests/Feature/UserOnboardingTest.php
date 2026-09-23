<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 20: ogrenci ekleme akisi. Rol -> paket -> koc (paket koclugu
 * kapsiyorsa) -> veli (en az bir, zorunlu). Hepsi tek kayitta; biri
 * eksikse hicbir sey olusmaz.
 */
class UserOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yonetici = User::factory()->admin()->create();
    }

    private function ekle(array $veri)
    {
        return $this->actingAs($this->yonetici)->from(route('admin.users.create'))
            ->post(route('admin.users.store'), array_merge([
                'name' => 'Ali Veli',
                'phone' => '0532 111 22 33',
                'role' => Role::Student->value,
            ], $veri));
    }

    private function ali(): ?User
    {
        return User::where('phone', '5321112233')->first();
    }

    // --- Paket --------------------------------------------------------------

    public function test_a_student_gets_the_package_and_the_existing_parent(): void
    {
        $paket = Package::factory()->tier1()->create(['monthly_price' => 7500]);
        $veli = User::factory()->parent()->create();

        $this->ekle(['package_id' => $paket->id, 'parent_ids' => [$veli->id]])
            ->assertSessionHasNoErrors();

        $ali = $this->ali();
        $this->assertTrue($ali->parents->contains($veli));
        $abonelik = $ali->currentSubscription();
        $this->assertTrue($abonelik->package->is($paket));
        $this->assertEquals(7500, $abonelik->price);
    }

    public function test_a_student_needs_a_package(): void
    {
        $veli = User::factory()->parent()->create();

        $this->ekle(['parent_ids' => [$veli->id]])->assertSessionHasErrors('package_id');

        $this->assertNull($this->ali());
    }

    public function test_an_addon_cannot_be_the_main_package(): void
    {
        $veli = User::factory()->parent()->create();

        $this->ekle([
            'package_id' => Package::factory()->examClubAddon()->create()->id,
            'parent_ids' => [$veli->id],
        ])->assertSessionHasErrors('package_id');
    }

    public function test_an_addon_joins_the_main_package(): void
    {
        $veli = User::factory()->parent()->create();
        $koc = User::factory()->create(['role' => Role::Coach->value]);

        $this->ekle([
            'package_id' => Package::factory()->tier2()->create()->id,
            'addon_ids' => [Package::factory()->examClubAddon()->create()->id],
            'coach_id' => $koc->id,
            'parent_ids' => [$veli->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Subscription::where('student_id', $this->ali()->id)->count());
        $this->assertTrue($this->ali()->entitlements()->examClub);
    }

    // --- Koc ----------------------------------------------------------------

    public function test_a_coaching_package_needs_a_coach(): void
    {
        $veli = User::factory()->parent()->create();

        $this->ekle([
            'package_id' => Package::factory()->tier2()->create()->id,
            'parent_ids' => [$veli->id],
        ])->assertSessionHasErrors('coach_id');

        $this->assertNull($this->ali());
    }

    public function test_the_coach_is_assigned(): void
    {
        $veli = User::factory()->parent()->create();
        $koc = User::factory()->create(['role' => Role::Coach->value]);

        $this->ekle([
            'package_id' => Package::factory()->tier2()->create()->id,
            'coach_id' => $koc->id,
            'parent_ids' => [$veli->id],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->ali()->coaches->contains($koc));
    }

    /** Cahit Hoca yonetici ve ayni zamanda koc (karar 11). */
    public function test_the_admin_can_be_the_coach(): void
    {
        $veli = User::factory()->parent()->create();

        $this->ekle([
            'package_id' => Package::factory()->tier3()->create()->id,
            'coach_id' => $this->yonetici->id,
            'parent_ids' => [$veli->id],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->ali()->coaches->contains($this->yonetici));
    }

    public function test_a_student_cannot_be_chosen_as_coach(): void
    {
        $veli = User::factory()->parent()->create();

        $this->ekle([
            'package_id' => Package::factory()->tier2()->create()->id,
            'coach_id' => User::factory()->student()->create()->id,
            'parent_ids' => [$veli->id],
        ])->assertSessionHasErrors('coach_id');
    }

    /** Tier 1 koclugu kapsamiyor: formdan koc gelse bile atanmaz. */
    public function test_no_coach_is_assigned_without_coaching(): void
    {
        $veli = User::factory()->parent()->create();
        $koc = User::factory()->create(['role' => Role::Coach->value]);

        $this->ekle([
            'package_id' => Package::factory()->tier1()->create()->id,
            'coach_id' => $koc->id,
            'parent_ids' => [$veli->id],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->ali()->coaches->isEmpty());
    }

    // --- Veli ---------------------------------------------------------------

    public function test_a_student_needs_at_least_one_parent(): void
    {
        $this->ekle(['package_id' => Package::factory()->tier1()->create()->id])
            ->assertSessionHasErrors('parent_ids');

        $this->assertNull($this->ali());
    }

    public function test_a_new_parent_is_created_on_the_spot(): void
    {
        $this->ekle([
            'package_id' => Package::factory()->tier1()->create()->id,
            'new_parent_name' => 'Ayşe Veli',
            'new_parent_phone' => '0533 999 88 77',
        ])->assertSessionHasNoErrors();

        $veli = User::where('phone', '5339998877')->sole();
        $this->assertSame(Role::Parent, $veli->role());
        $this->assertNull($veli->password);
        $this->assertTrue($this->ali()->parents->contains($veli));
    }

    public function test_a_new_parent_needs_a_phone(): void
    {
        $this->ekle([
            'package_id' => Package::factory()->tier1()->create()->id,
            'new_parent_name' => 'Ayşe Veli',
        ])->assertSessionHasErrors('new_parent_phone');

        $this->assertNull($this->ali());
    }

    public function test_a_new_parent_cannot_reuse_a_phone(): void
    {
        User::factory()->parent()->create(['phone' => '5339998877']);

        $this->ekle([
            'package_id' => Package::factory()->tier1()->create()->id,
            'new_parent_name' => 'Ayşe Veli',
            'new_parent_phone' => '0533 999 88 77',
        ])->assertSessionHasErrors('new_parent_phone');
    }

    public function test_the_new_parent_cannot_have_the_students_phone(): void
    {
        $this->ekle([
            'package_id' => Package::factory()->tier1()->create()->id,
            'new_parent_name' => 'Ayşe Veli',
            'new_parent_phone' => '0532 111 22 33',
        ])->assertSessionHasErrors('new_parent_phone');

        $this->assertNull($this->ali());
    }

    public function test_only_parents_can_be_linked(): void
    {
        $this->ekle([
            'package_id' => Package::factory()->tier1()->create()->id,
            'parent_ids' => [User::factory()->student()->create()->id],
        ])->assertSessionHasErrors('parent_ids.0');
    }

    // --- Diger roller -------------------------------------------------------

    public function test_other_roles_need_no_package_or_parent(): void
    {
        $this->ekle(['role' => Role::Coach->value])->assertSessionHasNoErrors();

        $this->assertSame(Role::Coach, $this->ali()->role());
    }

    // --- Butunluk: sonradan da korunur -------------------------------------

    public function test_the_last_parent_cannot_be_removed_from_a_student(): void
    {
        $veli = User::factory()->parent()->create();
        $ogrenci = User::factory()->student()->create(['phone' => '5321112233']);
        $ogrenci->parents()->attach($veli);

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->put(route('admin.users.update', $ogrenci), [
                'name' => $ogrenci->name,
                'phone' => '5321112233',
                'role' => Role::Student->value,
                'subscription_status' => 'active',
                'parent_ids' => [],
            ])->assertSessionHasErrors('parent_ids');

        $this->assertTrue($ogrenci->fresh()->parents->contains($veli));
    }

    public function test_the_only_parent_of_a_student_cannot_be_deleted(): void
    {
        $veli = User::factory()->parent()->create();
        User::factory()->student()->create()->parents()->attach($veli);

        $this->actingAs($this->yonetici)
            ->delete(route('admin.users.destroy', $veli))
            ->assertSessionHas('error');

        $this->assertNotNull($veli->fresh());
    }

    public function test_a_coach_cannot_be_attached_without_coaching(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();
        $koc = User::factory()->create(['role' => Role::Coach->value]);

        $this->actingAs($this->yonetici)
            ->post(route('admin.coaches.attach', $ogrenci), ['coach_id' => $koc->id])
            ->assertSessionHas('error');

        $this->assertTrue($ogrenci->fresh()->coaches->isEmpty());
    }

    public function test_the_create_form_offers_packages_coaches_and_parents(): void
    {
        Package::factory()->tier2()->create(['name' => 'Orta Paket']);
        User::factory()->create(['role' => Role::Coach->value, 'name' => 'Koç Mehmet']);
        User::factory()->parent()->create(['name' => 'Veli Fatma']);

        $this->actingAs($this->yonetici)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Orta Paket')
            ->assertSee('Koç Mehmet')
            ->assertSee('Veli Fatma');
    }
}
