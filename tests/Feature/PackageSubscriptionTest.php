<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\MonthlyBill;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 7 - Paket ve odeme (MVP #10, #11).
 *
 * Temel kural: fiyat abonelige KOPYALANIR; katalog degisince gecmis
 * abonelik ve fatura degismez.
 */
class PackageSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function bugun(string $gun = '2026-09-20'): void
    {
        $this->travelTo(Carbon::parse($gun . ' 12:00', config('kafe.timezone')));
    }

    // --- Paket katalogu -----------------------------------------------------

    public function test_an_admin_defines_a_package_with_included_products(): void
    {
        $yonetici = User::factory()->admin()->create();
        $cay = Product::create(['name' => 'Çay', 'unit_price' => 10, 'unit_type' => 'adet']);
        $kahve = Product::create(['name' => 'Kahve', 'unit_price' => 40, 'unit_type' => 'adet']);

        $this->actingAs($yonetici)->post('/yonetim/paketler', [
            'name' => 'Standart',
            'monthly_price' => 7500,
            'has_reserved_table' => 1,
            'weekly_mock_exams' => 1,
            'items' => [
                $cay->id => ['included' => 1, 'quantity' => '', 'period' => 'monthly'],
                $kahve->id => ['included' => 1, 'quantity' => 2, 'period' => 'daily'],
            ],
        ])->assertRedirect(route('admin.packages.index'));

        $paket = Package::sole();
        $this->assertTrue($paket->has_reserved_table);
        $this->assertSame(2, $paket->items()->count());
        $this->assertTrue($paket->items()->where('product_id', $cay->id)->first()->isUnlimited());
        $this->assertSame('günde 2 adet', $paket->items()->where('product_id', $kahve->id)->first()->label());

        // Kahveyi cikar, cayi 30/ay yap
        $this->actingAs($yonetici)->put(route('admin.packages.update', $paket), [
            'name' => 'Standart',
            'monthly_price' => 8000,
            'items' => [$cay->id => ['included' => 1, 'quantity' => 30, 'period' => 'monthly']],
        ])->assertRedirect();

        $this->assertSame(1, $paket->items()->count());
        $this->assertSame('ayda 30 adet', $paket->items()->first()->label());

        $this->actingAs($yonetici)->get('/yonetim/paketler')->assertOk()->assertSee('Standart')->assertSee('ayda 30 adet');
    }

    /** Dalga 19: seviye etiketi ve yeni hak bayraklari formdan yazilir. */
    public function test_an_admin_defines_a_tier_with_its_rights(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.packages.store'), [
                'name' => 'Kral',
                'tier' => 3,
                'monthly_price' => 12000,
                'has_reserved_table' => '1',
                'includes_coaching' => '1',
                'includes_exam_club' => '1',
                'includes_private_lessons' => '1',
            ])->assertSessionHasNoErrors();

        $paket = \App\Models\Package::where('name', 'Kral')->sole();
        $this->assertSame(3, $paket->tier);
        $this->assertTrue($paket->includes_exam_club);
        $this->assertTrue($paket->includes_private_lessons);
        $this->assertFalse($paket->is_addon);
    }

    public function test_an_admin_defines_an_addon_without_a_tier(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.packages.store'), [
                'name' => 'Deneme Kulübü',
                'tier' => '',
                'monthly_price' => 1500,
                'includes_exam_club' => '1',
                'is_addon' => '1',
            ])->assertSessionHasNoErrors();

        $paket = \App\Models\Package::where('name', 'Deneme Kulübü')->sole();
        $this->assertNull($paket->tier);
        $this->assertTrue($paket->is_addon);
    }

    public function test_a_tier_must_be_one_two_or_three(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.packages.store'), [
                'name' => 'Yanlis', 'tier' => 4, 'monthly_price' => 1,
            ])->assertSessionHasErrors('tier');
    }

    public function test_only_an_admin_manages_packages(): void
    {
        $this->post('/yonetim/paketler', ['name' => 'X', 'monthly_price' => 1])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->student()->create())->post('/yonetim/paketler', ['name' => 'X', 'monthly_price' => 1])->assertForbidden();
        $this->assertSame(0, Package::count());
    }

    // --- Abonelik -----------------------------------------------------------

    public function test_assigning_a_package_copies_the_price_and_activates_the_student(): void
    {
        $this->bugun();
        $yonetici = User::factory()->admin()->create();
        $ogrenci = User::factory()->student()->create(['subscription_status' => 'inactive']);
        $paket = Package::factory()->create(['monthly_price' => 7500]);

        $this->actingAs($yonetici)->post(route('admin.subscriptions.store', $ogrenci), [
            'package_id' => $paket->id,
            'starts_on' => '2026-09-20',
        ])->assertRedirect(route('admin.subscriptions.index', $ogrenci));

        $abonelik = Subscription::sole();
        $this->assertSame('7500.00', (string) $abonelik->price);
        $this->assertSame('2026-10-19', $abonelik->ends_on->toDateString());
        $this->assertSame(PaymentStatus::Pending, $abonelik->payment_status);

        $ogrenci->refresh();
        $this->assertSame('active', $ogrenci->subscription_status);
        $this->assertSame('2026-09-20', $ogrenci->subscription_start->toDateString());
        $this->assertSame($abonelik->id, $ogrenci->currentSubscription()->id);

        // Katalog fiyati degisir, abonelik degismez.
        $paket->update(['monthly_price' => 9000]);
        $this->assertSame('7500.00', (string) $abonelik->fresh()->price);
    }

    public function test_payments_settle_the_subscription_and_overdue_is_derived_from_the_due_date(): void
    {
        $this->bugun('2026-09-20');
        $yonetici = User::factory()->admin()->create();
        $abonelik = Subscription::factory()->create(['starts_on' => '2026-09-20', 'ends_on' => '2026-10-19', 'price' => 7500]);

        $this->assertSame(PaymentStatus::Pending, $abonelik->syncPaymentStatus());

        // Vade (7 gun) gecti, odeme yok -> gecikmis
        $this->bugun('2026-09-28');
        $this->assertSame(PaymentStatus::Overdue, $abonelik->syncPaymentStatus());
        $this->assertSame('overdue', $abonelik->fresh()->getRawOriginal('payment_status'));

        // Kismi odeme -> hala gecikmis, kalan dogru
        $this->actingAs($yonetici)->post(route('admin.subscriptions.payments.store', $abonelik), [
            'amount' => 5000, 'paid_at' => '2026-09-28', 'method' => 'transfer',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2500.0, $abonelik->fresh()->balance());
        $this->assertSame(PaymentStatus::Overdue, $abonelik->fresh()->payment_status);

        // Kalan odenir -> odendi
        $this->actingAs($yonetici)->post(route('admin.subscriptions.payments.store', $abonelik), [
            'amount' => 2500, 'paid_at' => '2026-09-29', 'method' => 'cash',
        ])->assertRedirect();
        $this->assertSame(PaymentStatus::Paid, $abonelik->fresh()->payment_status);

        // Odeme silinirse durum geri doner
        $this->actingAs($yonetici)->delete(route('admin.subscriptions.payments.destroy', Payment::latest('id')->first()))->assertRedirect();
        $this->assertSame(PaymentStatus::Overdue, $abonelik->fresh()->payment_status);
    }

    public function test_a_cancelled_subscription_stays_cancelled_and_takes_no_payment(): void
    {
        $this->bugun();
        $yonetici = User::factory()->admin()->create();
        $abonelik = Subscription::factory()->create();

        $this->actingAs($yonetici)->post(route('admin.subscriptions.cancel', $abonelik))->assertRedirect();
        $this->assertSame(PaymentStatus::Cancelled, $abonelik->fresh()->payment_status);
        $this->assertNull($abonelik->student->currentSubscription());

        $this->actingAs($yonetici)->post(route('admin.subscriptions.payments.store', $abonelik), [
            'amount' => 100, 'paid_at' => '2026-09-20', 'method' => 'cash',
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, Payment::count());
        $this->assertSame(PaymentStatus::Cancelled, $abonelik->fresh()->syncPaymentStatus());
    }

    public function test_the_subscription_page_and_overview_render(): void
    {
        $this->bugun('2026-09-28');
        $yonetici = User::factory()->admin()->create();
        $paket = Package::factory()->create(['name' => 'Standart']);
        $gecikmis = Subscription::factory()->create(['package_id' => $paket->id, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        $odenen = Subscription::factory()->create(['package_id' => $paket->id, 'starts_on' => '2026-09-20', 'ends_on' => '2026-10-19']);
        Payment::factory()->create(['subscription_id' => $odenen->id, 'amount' => 7500]);

        $this->actingAs($yonetici)->get(route('admin.subscriptions.index', $gecikmis->student))
            ->assertOk()->assertSee('Paket ata')->assertSee('Gecikmiş')->assertSee('Vade 08.09.2026');

        $this->actingAs($yonetici)->get('/yonetim/odemeler')
            ->assertOk()->assertSee($gecikmis->student->name)->assertSee($odenen->student->name);

        $this->actingAs($yonetici)->get('/yonetim/odemeler?durum=overdue')
            ->assertOk()->assertSee($gecikmis->student->name)->assertDontSee($odenen->student->name);
    }

    public function test_the_student_and_parent_see_the_package_and_payment_state(): void
    {
        $this->bugun();
        // Vade gecmesin diye bugun baslar; factory ay basindan baslatir.
        $abonelik = Subscription::factory()->create([
            'package_id' => Package::factory()->create(['name' => 'Standart'])->id,
            'starts_on' => '2026-09-20', 'ends_on' => '2026-10-19',
        ]);
        $ogrenci = $abonelik->student;
        $veli = User::factory()->parent()->create();
        $veli->students()->attach($ogrenci->id);

        $this->actingAs($ogrenci)->get('/kullanici/panel')->assertOk()->assertSee('Standart')->assertSee('Ödeme: Bekliyor');
        $this->actingAs($veli)->get('/veli')->assertOk()->assertSee('Standart')->assertSee('Ödeme: Bekliyor');
    }

    // --- Fatura -------------------------------------------------------------

    public function test_the_monthly_bill_reads_the_package_amount_from_the_subscription(): void
    {
        $this->bugun();
        $paket = Package::factory()->create(['monthly_price' => 7500]);
        $abonelik = Subscription::factory()->create(['package_id' => $paket->id, 'starts_on' => '2026-09-05', 'ends_on' => '2026-10-04', 'price' => 7000]);
        Subscription::factory()->create(['student_id' => $abonelik->student_id, 'package_id' => $paket->id, 'starts_on' => '2026-10-05', 'ends_on' => '2026-11-04', 'price' => 7500]);

        $paket->update(['monthly_price' => 9999]);

        $fatura = MonthlyBill::generateForUserMonth($abonelik->student, 2026, 9);

        $this->assertSame('7000.00', (string) $fatura->package_amount);
        $this->assertSame(7000.0, $fatura->grandTotal());
        $this->assertSame('0.00', (string) MonthlyBill::generateForUserMonth($abonelik->student, 2026, 8)->package_amount);
        $this->assertSame('7500.00', (string) MonthlyBill::generateForUserMonth($abonelik->student, 2026, 10)->package_amount);
    }
}
