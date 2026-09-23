<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 10a - Mobil kabuk: alt menu + uygulama ici QR okuyucu.
 *
 * Okuyucunun kendisi tarayicida calisiyor (getUserMedia + BarcodeDetector) ve
 * burada test edilemez. Test edilebilen ve asil onemli olan YEDEK YOL: kamera
 * izni reddedilirse ya da tarayici desteklemiyorsa ogrenci masadaki kodu elle
 * yazabilmeli. Tek yol olarak kameraya baglanmak, izni kapali bir telefonu
 * sistem disina atardi.
 *
 * Kod zaten etikete basiliyor (QR'in altinda metin olarak), yani mevcut
 * etiketler bu yola hazir - yeniden basmak gerekmiyor.
 */
class MobileShellTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    public function test_a_table_code_typed_by_hand_opens_the_table(): void
    {
        $masa = StudyTable::create(['name' => 'Pencere Kenarı']);

        $this->actingAs($this->ogrenci())
            ->post(route('table.find'), ['code' => $masa->qr_code])
            ->assertRedirect(route('table.scan', $masa->qr_code));
    }

    /**
     * Kodlar buyuk harf uretiliyor ama telefon klavyesi kucuk harf yaziyor ve
     * yapistirirken bosluk bulasiyor. Bunlari kullaniciya duzelttirmek,
     * yedek yolu kullanilamaz kilar.
     */
    public function test_the_code_is_forgiving_about_case_and_spaces(): void
    {
        $masa = StudyTable::create(['name' => 'Köşe Masa']);

        $this->actingAs($this->ogrenci())
            ->post(route('table.find'), ['code' => '  ' . strtolower($masa->qr_code) . ' '])
            ->assertRedirect(route('table.scan', $masa->qr_code));
    }

    public function test_an_unknown_code_comes_back_with_an_error(): void
    {
        $this->actingAs($this->ogrenci())
            ->post(route('table.find'), ['code' => 'MASA-YOKBOYLE'])
            ->assertSessionHasErrors('code');
    }

    public function test_an_empty_code_is_refused(): void
    {
        $this->actingAs($this->ogrenci())
            ->post(route('table.find'), ['code' => ''])
            ->assertSessionHasErrors('code');
    }

    public function test_a_guest_cannot_look_up_a_table(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);

        $this->post(route('table.find'), ['code' => $masa->qr_code])
            ->assertRedirect(route('login'));
    }

    /** Baslik metnine {{ }} yazilirsa Blade derlemez, ham PHP ekrana cikar. */
    public function test_the_student_greeting_shows_the_name_not_php(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertSee('Hoş Geldin, ' . $ogrenci->name . '!')
            ->assertDontSee('<?php', false);
    }

    public function test_the_student_panel_offers_the_scanner(): void
    {
        // Masa hakki paketten gelir; paketsiz ogrenciye okuyucu sunulmaz.
        $this->actingAs(User::factory()->student()->withPackage(\App\Models\Package::factory()->tier1())->create())
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('QR Okut');
    }

    /**
     * Alt menu telefonda gezinmenin tamami; kenar cubugu 1024px altinda
     * kullanilmiyor. Sessizce kaybolursa ogrenci panelden baska hicbir yere
     * gidemez hale gelir - bu yuzden her rol icin sabitleniyor.
     */
    public function test_the_student_panel_has_a_bottom_navigation(): void
    {
        $this->actingAs($this->ogrenci())
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('bottom-nav');
    }

    public function test_the_parent_panel_has_a_bottom_navigation(): void
    {
        $this->actingAs(User::factory()->parent()->create())
            ->get(route('parent.dashboard'))
            ->assertOk()
            ->assertSee('bottom-nav');
    }

    public function test_the_admin_panel_has_a_bottom_navigation(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Admin->value]))
            ->get(route('admin.live'))
            ->assertOk()
            ->assertSee('bottom-nav');
    }

    /**
     * Okuyucu sayfasi kamera acilmasa da ise yaramali: elle giris formu her
     * durumda orada. Kameraya bagli tek yol birakmak, izni kapali telefonu
     * sistem disina atardi.
     */
    public function test_the_scanner_page_always_offers_the_manual_form(): void
    {
        $this->actingAs($this->ogrenci())
            ->get(route('table.scanner'))
            ->assertOk()
            ->assertSee('Masadaki kodu yaz')
            ->assertSee(route('table.find'));
    }
}
