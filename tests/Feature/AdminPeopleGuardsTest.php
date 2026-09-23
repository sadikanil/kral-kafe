<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DiscrepancyLog;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockPhoto;
use App\Models\StockRecord;
use App\Models\StudentParent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Yonetim - kisiler: QA 29-34 duzeltmelerinin smoke testlerinin disinda
 * kalan kenarlari (faz 2, grup F).
 */
class AdminPeopleGuardsTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;

    protected function setUp(): void
    {
        parent::setUp();

        $this->yonetici = User::factory()->admin()->create(['name' => 'Cahit Hoca']);
    }

    private function formVerisi(User $u, array $ek = []): array
    {
        return array_merge([
            'name' => $u->name,
            'phone' => $u->phone,
            'email' => $u->email,
            'role' => $u->role,
            'subscription_status' => $u->subscription_status ?? 'active',
        ], $ek);
    }

    private function urun(): Product
    {
        return Product::create(['name' => 'Ayran', 'unit_price' => 20, 'unit_type' => 'adet', 'is_active' => true]);
    }

    // --- QA 30: kendi rolunu dusurme ------------------------------------------

    public function test_the_admin_can_still_change_another_admins_role(): void
    {
        $diger = User::factory()->admin()->create();

        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $diger), $this->formVerisi($diger, ['role' => Role::Coach->value]))
            ->assertSessionHasNoErrors();

        $this->assertSame('coach', $diger->fresh()->role);
    }

    public function test_the_admin_keeps_editing_their_own_name(): void
    {
        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $this->yonetici), $this->formVerisi($this->yonetici, ['name' => 'Cahit Yılmaz']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame('Cahit Yılmaz', $this->yonetici->fresh()->name);
    }

    public function test_the_own_edit_page_offers_only_the_admin_role(): void
    {
        // Diger roller listede kalir ama secilemez: rol listesi her formda tam.
        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $this->yonetici))
            ->assertOk()
            ->assertSee('<option value="admin" selected>', false)
            ->assertSee('<option value="coach" disabled>', false)
            ->assertSee('Kendi rolünü değiştiremezsin');

        $koc = User::factory()->create(['role' => Role::Coach->value]);
        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $koc))
            ->assertOk()
            ->assertSee('<option value="coach" selected>', false)
            ->assertSee('<option value="admin" >', false);
    }

    public function test_a_refused_self_demotion_redraws_the_own_page_with_admin_selected(): void
    {
        // Reddedilen istekten sonra old('role') koc kalir; kendi sayfasinda o
        // deger secili cizilirse secili ve kilitli ayni secenege duser.
        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $this->yonetici))
            ->put(route('admin.users.update', $this->yonetici), $this->formVerisi($this->yonetici, ['role' => Role::Coach->value]))
            ->assertRedirect(route('admin.users.edit', $this->yonetici))
            ->assertSessionHasErrors('role');

        $this->get(route('admin.users.edit', $this->yonetici))
            ->assertOk()
            ->assertSee('<option value="admin" selected>', false)
            ->assertSee('<option value="coach" disabled>', false)
            ->assertDontSee('selecteddisabled', false);
    }

    // --- QA 29: veli formu --------------------------------------------------

    public function test_a_refused_parent_form_saves_nothing_and_names_the_student(): void
    {
        $veli = User::factory()->parent()->create(['name' => 'Gülşen Yıldız']);
        $tek = User::factory()->student()->create(['name' => 'Zeynep Tek']);
        $ikili = User::factory()->student()->create(['name' => 'Ali İki']);
        StudentParent::factory()->create(['student_id' => $tek->id, 'parent_id' => $veli->id]);
        StudentParent::factory()->create(['student_id' => $ikili->id, 'parent_id' => $veli->id]);
        StudentParent::factory()->create(['student_id' => $ikili->id, 'parent_id' => User::factory()->parent()->create()->id]);

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $veli))
            ->put(route('admin.users.update', $veli), $this->formVerisi($veli, ['name' => 'Yeni Ad', 'student_ids' => '']))
            ->assertRedirect(route('admin.users.edit', $veli))
            ->assertSessionHasErrors(['student_ids' => 'Öğrencinin en az bir velisi olmalı: Zeynep Tek için tek veli bu. Önce öğrenciye başka bir veli bağlayın.']);

        // Hicbir sey yarim kaydedilmez: ad da, iki velili ogrencinin bagi da yerinde.
        $this->assertSame('Gülşen Yıldız', $veli->fresh()->name);
        $this->assertEqualsCanonicalizing([$tek->id, $ikili->id], $veli->students()->pluck('users.id')->all());
    }

    public function test_a_parent_form_that_keeps_the_only_child_ticked_saves(): void
    {
        $veli = User::factory()->parent()->create();
        $tek = User::factory()->student()->create();
        StudentParent::factory()->create(['student_id' => $tek->id, 'parent_id' => $veli->id]);

        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $veli), $this->formVerisi($veli, ['student_ids' => [$tek->id]]))
            ->assertSessionHasNoErrors();

        $this->assertSame([$tek->id], $veli->students()->pluck('users.id')->all());
    }

    public function test_the_only_parent_of_a_student_keeps_the_parent_role(): void
    {
        // Bag kalir ama eski veli veli panelini kaybeder; ogrenci formu yalnizca
        // veli rolundekileri listeledigi icin o form da hep reddedilirdi.
        $veli = User::factory()->parent()->create();
        $tek = User::factory()->student()->create(['name' => 'Zeynep Tek']);
        StudentParent::factory()->create(['student_id' => $tek->id, 'parent_id' => $veli->id]);

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $veli))
            ->put(route('admin.users.update', $veli), $this->formVerisi($veli, [
                'name' => 'Yeni Ad', 'role' => Role::Coach->value, 'student_ids' => [$tek->id],
            ]))
            ->assertRedirect(route('admin.users.edit', $veli))
            ->assertSessionHasErrors(['role' => 'Bu velinin rolü değiştirilemez: Zeynep Tek için tek veli. Önce öğrenciye başka bir veli bağlayın.']);

        $this->assertSame('parent', $veli->fresh()->role);
        $this->assertNotSame('Yeni Ad', $veli->fresh()->name);
    }

    public function test_a_parent_whose_children_have_another_parent_can_change_role(): void
    {
        $veli = User::factory()->parent()->create();
        $ikili = User::factory()->student()->create();
        StudentParent::factory()->create(['student_id' => $ikili->id, 'parent_id' => $veli->id]);
        StudentParent::factory()->create(['student_id' => $ikili->id, 'parent_id' => User::factory()->parent()->create()->id]);

        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $veli), $this->formVerisi($veli, ['role' => Role::Coach->value, 'student_ids' => [$ikili->id]]))
            ->assertSessionHasNoErrors();

        $this->assertSame('coach', $veli->fresh()->role);
    }

    // --- QA 34: veli aramasi ------------------------------------------------

    public function test_the_parent_search_text_holds_the_phone_as_shown(): void
    {
        User::factory()->parent()->create(['name' => 'Irmak Ilgın', 'phone' => '5321112233']);

        $this->actingAs($this->yonetici)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('data-ara="ırmak ılgın 0532 111 22 33 5321112233"', false);
    }

    // --- QA 31, 32: silinen yoneticinin stok gecmisi -------------------------

    public function test_deleting_an_admin_keeps_their_history_with_an_empty_author(): void
    {
        $eski = User::factory()->admin()->create();
        $urun = $this->urun();
        $konum = Location::first();

        $tutarsizlik = DiscrepancyLog::create([
            'location_id' => $konum->id, 'product_id' => $urun->id,
            'expected_quantity' => 10, 'actual_quantity' => 8, 'difference' => -2, 'record_type' => 'closing',
            'detected_at' => now(), 'resolved' => true, 'resolution_notes' => 'Sayım hatası',
            'resolved_by' => $eski->id, 'resolved_at' => now(),
        ]);
        $sayim = StockRecord::create([
            'location_id' => $konum->id, 'product_id' => $urun->id, 'record_type' => 'closing',
            'verified_quantity' => 12, 'admin_id' => $eski->id, 'recorded_at' => now(),
        ]);
        $foto = StockPhoto::create([
            'location_id' => $konum->id, 'batch_id' => (string) Str::uuid(), 'record_type' => 'closing',
            'photo_path' => 'stok/a.jpg', 'admin_id' => $eski->id,
        ]);

        $this->actingAs($this->yonetici)->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $eski))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'Kullanıcı başarıyla silindi.');

        $this->assertNull($tutarsizlik->fresh()->resolved_by);
        $this->assertTrue($tutarsizlik->fresh()->resolved);
        $this->assertNull($sayim->fresh()->admin_id);
        $this->assertNull($foto->fresh()->admin_id);
    }

    public function test_the_rebuilt_stock_tables_keep_their_other_foreign_keys(): void
    {
        // SQLite'ta FK degisikligi tabloyu yeniden kurar; konum ve urun
        // baglari (CASCADE) yeniden kurulumda kaybolmamali.
        $kural = fn (string $tablo) => collect(DB::select("PRAGMA foreign_key_list({$tablo})"))
            ->mapWithKeys(fn ($fk) => [$fk->from => $fk->on_delete])->all();

        $this->assertSame(['admin_id' => 'SET NULL', 'location_id' => 'CASCADE', 'product_id' => 'CASCADE'],
            collect($kural('stock_records'))->sortKeys()->all());
        $this->assertSame(['admin_id' => 'SET NULL', 'location_id' => 'CASCADE'],
            collect($kural('stock_photos'))->sortKeys()->all());
        $this->assertSame(['location_id' => 'CASCADE', 'product_id' => 'CASCADE', 'resolved_by' => 'SET NULL'],
            collect($kural('discrepancy_logs'))->sortKeys()->all());

        // Yeniden kurulum enum'un CHECK'ini da duser; yerel/test semasi canlinin
        // reddettigi record_type degerini kabul etmemeli.
        foreach (['stock_records', 'stock_photos', 'discrepancy_logs'] as $tablo) {
            $this->assertStringContainsString("check (\"record_type\" in ('opening', 'closing'))",
                DB::selectOne('select sql from sqlite_master where name = ?', [$tablo])->sql, $tablo);
        }
    }

    // --- E-posta: harf farki ayni adres ---------------------------------------

    /**
     * User e-postayi kucuk harfle kaydeder; tekillik kurali ise yazilani
     * harf duyarli karsilastiriyordu. "DUP@..." dogrulamadan gecip tekil
     * indekse carpiyordu: 500.
     */
    public function test_creating_a_user_with_a_case_variant_email_is_refused(): void
    {
        User::factory()->create(['role' => Role::Coach->value, 'email' => 'dup@kralkafe.com']);

        $this->actingAs($this->yonetici)
            ->post(route('admin.users.store'), [
                'name' => 'Yeni Koç', 'email' => 'DUP@kralkafe.com', 'role' => Role::Coach->value,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'dup@kralkafe.com')->count());
    }

    public function test_updating_a_user_to_a_case_variant_email_is_refused(): void
    {
        User::factory()->create(['role' => Role::Coach->value, 'email' => 'dup@kralkafe.com']);
        $diger = User::factory()->create(['role' => Role::Coach->value, 'email' => 'diger@kralkafe.com']);

        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $diger), $this->formVerisi($diger, ['email' => ' DUP@KralKafe.com ']))
            ->assertSessionHasErrors('email');

        $this->assertSame('diger@kralkafe.com', $diger->fresh()->email);
    }
}
