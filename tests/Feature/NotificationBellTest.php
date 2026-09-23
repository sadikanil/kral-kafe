<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Location;
use App\Models\Notification;
use App\Models\Product;
use App\Models\StockRecord;
use App\Models\User;
use App\Services\NotificationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 27: bildirim zili. Herkesin ust barinda zil + son bildirimler;
 * stok sayimi hatirlatmasi yoneticiye buradan duser. Push yok (karar:
 * site ici zil yeterli).
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bildirim(User $kime, array $ek = []): Notification
    {
        static $n = 0;

        return Notification::create(array_merge([
            'type' => NotificationType::Absence->value,
            'user_id' => $kime->id,
            'unique_key' => 'test:' . (++$n),
            'title' => 'Deneme bildirimi ' . $n,
        ], $ek));
    }

    private function sayim(string $gun): void
    {
        StockRecord::create([
            'location_id' => Location::create(['name' => 'Raf ' . $gun, 'type' => 'shelf'])->id,
            'product_id' => Product::create(['name' => 'Ürün ' . $gun, 'unit_price' => 1, 'unit_type' => 'adet'])->id,
            'record_type' => 'closing',
            // Sayimi yapan; yonetici OLUSTURMUYOR ki "yalnizca yoneticiler"
            // testine sahte alici eklenmesin.
            'admin_id' => (User::first() ?? User::factory()->student()->create())->id,
            'verified_quantity' => 5,
            'recorded_at' => Carbon::parse($gun . ' 12:00'),
        ]);
    }

    // --- Stok sayimi hatirlatmasi -------------------------------------------

    public function test_the_admin_is_reminded_after_a_week_without_counting(): void
    {
        $yonetici = User::factory()->admin()->create();
        $this->sayim('2026-09-16');

        app(NotificationBuilder::class)->stockCountReminders('2026-09-23');

        $this->assertSame(NotificationType::StockCount, Notification::for($yonetici)->sole()->type);
    }

    public function test_no_reminder_after_a_recent_count(): void
    {
        User::factory()->admin()->create();
        $this->sayim('2026-09-20');

        app(NotificationBuilder::class)->stockCountReminders('2026-09-23');

        $this->assertSame(0, Notification::count());
    }

    /** Sayim yapilmazsa her gun degil, her 7 gunde bir hatirlatilir. */
    public function test_the_reminder_does_not_nag_every_day(): void
    {
        User::factory()->admin()->create();
        $this->sayim('2026-09-16');
        $uretici = app(NotificationBuilder::class);

        foreach (['2026-09-23', '2026-09-24', '2026-09-25', '2026-09-30'] as $gun) {
            $uretici->stockCountReminders($gun);
        }

        $this->assertSame(2, Notification::count());
    }

    public function test_running_twice_the_same_day_does_not_duplicate(): void
    {
        User::factory()->admin()->create();
        $this->sayim('2026-09-16');

        app(NotificationBuilder::class)->stockCountReminders('2026-09-23');
        app(NotificationBuilder::class)->stockCountReminders('2026-09-23');

        $this->assertSame(1, Notification::count());
    }

    public function test_only_admins_get_the_stock_reminder(): void
    {
        User::factory()->student()->create();
        $this->sayim('2026-09-16');

        app(NotificationBuilder::class)->stockCountReminders('2026-09-23');

        $this->assertSame(0, Notification::count());
    }

    public function test_the_daily_cron_includes_the_stock_reminder(): void
    {
        config(['kafe.cron_anahtari' => 'gizli']);

        $this->withToken('gizli')->get(route('cron.daily'))
            ->assertOk()
            ->assertJsonStructure(['stok_sayimi']);
    }

    // --- Zil ----------------------------------------------------------------

    public function test_every_role_sees_the_bell_with_the_unread_count(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->student()->create(), User::factory()->parent()->create()] as $kisi) {
            $this->bildirim($kisi);
            $this->bildirim($kisi, ['read_at' => now()]);

            $this->actingAs($kisi)->get(route($kisi->homeRoute()))
                ->assertSee('notif-bell', false)
                ->assertSee('notif-count">1<', false);
        }
    }

    public function test_the_bell_lists_the_latest_notifications(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->bildirim($ogrenci, ['title' => 'Yarın deneme var: TYT']);

        $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertSee('Yarın deneme var: TYT');
    }

    public function test_the_notification_page_marks_them_read(): void
    {
        $ogrenci = User::factory()->student()->create();
        $bildirim = $this->bildirim($ogrenci);

        $this->actingAs($ogrenci)->get(route('notifications.index'))->assertOk()->assertSee($bildirim->title);

        $this->assertNotNull($bildirim->fresh()->read_at);
    }

    public function test_nobody_sees_someone_elses_notifications(): void
    {
        $baskasi = User::factory()->student()->create();
        $this->bildirim($baskasi, ['title' => 'Gizli bildirim']);

        $this->actingAs(User::factory()->student()->create())->get(route('notifications.index'))
            ->assertDontSee('Gizli bildirim');
        $this->assertNull(Notification::sole()->read_at);
    }
}
