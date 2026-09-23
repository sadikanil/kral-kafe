<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Location;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 29 - Stok urunun uzerinde, konum bir etiket.
 *
 * stock_quantity null = takip KAPALI (sicak icecek, cay). Kritik sayiya
 * inildiginde yoneticiye bir kez bildirim: her satista degil, sinir
 * GECILDIGINDE. Stok yeniden kritigin ustune cikip tekrar inerse yeniden.
 */
class ProductStockTest extends TestCase
{
    use RefreshDatabase;

    private function urun(?int $stok, ?int $kritik = null, array $ek = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Çamlıca Gazoz', 'unit_price' => 20, 'unit_type' => 'Cam Şişe',
            'stock_quantity' => $stok, 'critical_quantity' => $kritik,
        ], $ek));
    }

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    private function uyarilar()
    {
        return Notification::where('type', NotificationType::LowStock->value);
    }

    // --- Durum ---------------------------------------------------------------

    public function test_an_empty_stock_means_tracking_is_off(): void
    {
        $this->assertFalse($this->urun(null)->tracksStock());
        $this->assertTrue($this->urun(0)->tracksStock());
    }

    public function test_the_stock_status(): void
    {
        $this->assertSame('untracked', $this->urun(null)->stockStatus());
        $this->assertSame('out', $this->urun(0, 5)->stockStatus());
        $this->assertSame('critical', $this->urun(5, 5)->stockStatus());
        $this->assertSame('ok', $this->urun(6, 5)->stockStatus());
        $this->assertSame('ok', $this->urun(3)->stockStatus(), 'Kritik sayi yoksa yalnizca 0 uyarir');
    }

    public function test_adjusting_an_untracked_product_does_nothing(): void
    {
        $urun = $this->urun(null);

        $urun->adjustStock(-2);

        $this->assertNull($urun->fresh()->stock_quantity);
    }

    public function test_adjusting_changes_the_stock(): void
    {
        $urun = $this->urun(10);

        $urun->adjustStock(-3);
        $this->assertSame(7, $urun->fresh()->stock_quantity);

        $urun->adjustStock(2);
        $this->assertSame(9, $urun->fresh()->stock_quantity);
    }

    // --- Kritik stok bildirimi -----------------------------------------------

    public function test_the_admin_is_notified_when_stock_falls_to_the_critical_level(): void
    {
        $yonetici = $this->yonetici();
        $urun = $this->urun(6, 5);

        $urun->adjustStock(-1);

        $uyari = $this->uyarilar()->sole();
        $this->assertSame($yonetici->id, $uyari->user_id);
        $this->assertSame($urun->id, $uyari->related_id);
        $this->assertStringContainsString('Çamlıca Gazoz', $uyari->title);
        $this->assertStringContainsString('5', $uyari->body);
    }

    public function test_further_sales_below_the_level_do_not_repeat_the_alert(): void
    {
        $this->yonetici();
        $urun = $this->urun(6, 5);

        $urun->adjustStock(-1);
        $urun->adjustStock(-1);
        $urun->adjustStock(-1);

        $this->assertSame(1, $this->uyarilar()->count());
    }

    public function test_restocking_and_falling_again_alerts_again(): void
    {
        $this->yonetici();
        $urun = $this->urun(6, 5);

        $urun->adjustStock(-1);
        $urun->update(['stock_quantity' => 24]);
        $urun->adjustStock(-19);

        $this->assertSame(2, $this->uyarilar()->count());
    }

    public function test_raising_the_critical_level_above_the_stock_alerts(): void
    {
        $this->yonetici();
        $urun = $this->urun(4);

        $urun->update(['critical_quantity' => 5]);

        $this->assertSame(1, $this->uyarilar()->count());
    }

    public function test_a_new_product_already_at_the_critical_level_alerts(): void
    {
        $this->yonetici();

        $this->urun(2, 5);

        $this->assertSame(1, $this->uyarilar()->count());
    }

    public function test_no_alert_without_a_critical_level_or_tracking(): void
    {
        $this->yonetici();
        $this->urun(1)->adjustStock(-1);
        $this->urun(null, null)->adjustStock(-1);

        $this->assertSame(0, $this->uyarilar()->count());
    }

    public function test_only_admins_receive_the_alert(): void
    {
        $yonetici = $this->yonetici();
        User::factory()->student()->create();

        $this->urun(1, 5);

        $this->assertSame([$yonetici->id], $this->uyarilar()->pluck('user_id')->all());
    }

    // --- Konum etiketi -------------------------------------------------------

    public function test_the_location_tags_leave_out_the_system_location(): void
    {
        Location::selfService();
        Location::create(['name' => 'Arka Depo']);

        $adlar = Location::tags()->pluck('name');

        $this->assertContains('Arka Depo', $adlar);
        $this->assertNotContains('Self Adisyon', $adlar);
        $this->assertSame($adlar->sort()->values()->all(), $adlar->all(), 'Ada gore sirali');
    }

    public function test_a_tag_is_reused_by_name(): void
    {
        $depo = Location::create(['name' => 'Arka Depo']);

        $this->assertSame($depo->id, Location::tagNamed('  Arka Depo ')->id);
    }

    public function test_an_unknown_tag_name_creates_a_tag(): void
    {
        $once = Location::count();

        $depo = Location::tagNamed('Arka Depo');

        $this->assertSame('Arka Depo', $depo->name);
        $this->assertSame($once + 1, Location::count());
    }
}
