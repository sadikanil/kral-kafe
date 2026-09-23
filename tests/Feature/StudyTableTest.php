<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\StudyTable;
use App\Models\User;
use App\Support\QrImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Dalga 2 - Masa (MVP #1).
 *
 * Masa QR'lari BASILIP duvara yapistirilacak. Bu, yazilim tarafinda alisilmadik
 * bir kisit yaratir: uretilen kod bir daha asla degismemeli. Kod degisirse hata
 * ekranda gorunmez - kafedeki fiziksel etiketler sessizce olur.
 */
class StudyTableTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        return User::factory()->create([
            'role' => Role::Admin->value,
            'subscription_status' => 'active',
        ]);
    }

    public function test_a_table_gets_a_qr_code_when_it_is_created(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);

        $this->assertMatchesRegularExpression('/^MASA-[A-Z0-9]{8}$/', $masa->qr_code);
        $this->assertTrue($masa->is_active);
    }

    /**
     * Basili etiket kisiti. Bu testin kirilmasi "masa adini degistirince
     * duvardaki QR'lar oldu" demektir.
     */
    public function test_the_qr_code_never_changes_after_creation(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);
        $ilkKod = $masa->qr_code;

        $masa->update(['name' => 'Pencere Kenarı', 'is_active' => false]);

        $this->assertSame($ilkKod, $masa->fresh()->qr_code);
    }

    public function test_the_scan_address_is_built_in_one_place(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);

        $this->assertSame(url("/masa/{$masa->qr_code}"), $masa->qr_url);
    }

    public function test_an_admin_can_create_and_rename_a_table(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)
            ->post(route('admin.tables.store'), ['name' => 'Masa 7'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.tables.index'));

        $masa = StudyTable::firstWhere('name', 'Masa 7');
        $this->assertNotNull($masa);

        $this->actingAs($yonetici)
            ->put(route('admin.tables.update', $masa), ['name' => 'Masa 7A', 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Masa 7A', $masa->fresh()->name);
        $this->assertSame($masa->qr_code, $masa->fresh()->qr_code);
    }

    public function test_a_table_name_is_required(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.tables.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_a_student_cannot_reach_table_management(): void
    {
        $ogrenci = User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($ogrenci)->get(route('admin.tables.index'))->assertForbidden();
    }

    public function test_the_qr_page_shows_the_code_and_the_scan_address(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 3']);

        $this->actingAs($this->yonetici())
            ->get(route('admin.tables.qr', $masa))
            ->assertOk()
            ->assertSee($masa->qr_code)
            ->assertSee($masa->qr_url);
    }

    public function test_the_print_sheet_covers_active_tables_only(): void
    {
        $acik = StudyTable::create(['name' => 'Masa 1']);
        $kapali = StudyTable::create(['name' => 'Depo Masası', 'is_active' => false]);

        $this->actingAs($this->yonetici())
            ->get(route('admin.tables.print-qr'))
            ->assertOk()
            ->assertSee($acik->qr_code)
            ->assertDontSee($kapali->qr_code);
    }

    /**
     * Masa duzeni: 11 cift kisilik masa (A/B) + 10 tek kisilik = 32 yer.
     * Duz isim sirasinda "Masa 10" "Masa 2"nin onune duser; etiketi kesip
     * masalara dagitan kisi ve listeye bakan yonetici sayi sirasi bekler.
     */
    public function test_the_table_list_is_in_number_order(): void
    {
        foreach (['Masa 12', 'Masa 10 · A', 'Masa 2 · A', 'Masa 1 · B', 'Masa 1 · A'] as $ad) {
            StudyTable::create(['name' => $ad]);
        }

        $this->actingAs($this->yonetici())
            ->get(route('admin.tables.index'))
            ->assertSeeInOrder(['Masa 1 · A', 'Masa 1 · B', 'Masa 2 · A', 'Masa 10 · A', 'Masa 12']);
    }

    public function test_the_print_sheet_is_in_number_order(): void
    {
        foreach (['Masa 21', 'Masa 11 · B', 'Masa 3 · A'] as $ad) {
            StudyTable::create(['name' => $ad]);
        }

        $this->actingAs($this->yonetici())
            ->get(route('admin.tables.print-qr'))
            ->assertSeeInOrder(['Masa 3 · A', 'Masa 11 · B', 'Masa 21']);
    }

    /**
     * 32 yer tek sayfada: sayfalama sirayi sayfalar arasinda bolerdi.
     * "Masa 9" duz isim sirasinda sonuncu, yani eskiden ikinci sayfadaydi.
     */
    public function test_all_32_seats_are_listed_on_one_page(): void
    {
        for ($n = 1; $n <= 32; $n++) {
            StudyTable::create(['name' => "Masa {$n}"]);
        }

        $this->actingAs($this->yonetici())
            ->get(route('admin.tables.index'))
            ->assertSee('<strong>Masa 9</strong>', false);
    }

    /**
     * Dalga 3'e kadar /masa/{kod} adresi YOK. O ana kadar basilan her etiket
     * 404'e gider ve fiziksel etiketi yeniden basmak pahalidir.
     *
     * Uyari rotanin VARLIGINA bagli: Dalga 3 rotayi ekleyince uyari kendiliginden
     * kaybolur, kimsenin eski bir metni silmeyi hatirlamasi gerekmez.
     */
    public function test_the_print_sheet_warns_while_the_scan_route_is_missing(): void
    {
        StudyTable::create(['name' => 'Masa 1']);

        $yanit = $this->actingAs($this->yonetici())->get(route('admin.tables.print-qr'));

        if (Route::has('table.scan')) {
            $yanit->assertDontSee('henüz yayında değil');
        } else {
            $yanit->assertSee('henüz yayında değil');
        }
    }

    public function test_the_qr_image_url_is_built_in_one_place(): void
    {
        $this->assertStringContainsString('size=260x260', QrImage::url('https://ornek.test', 260));
        $this->assertStringContainsString(urlencode('https://ornek.test'), QrImage::url('https://ornek.test'));

        $elle = [];
        foreach (['app', 'resources', 'routes', 'database'] as $kok) {
            foreach ($this->dosyalar(base_path($kok)) as $dosya) {
                if (str_ends_with($dosya, 'app/Support/QrImage.php')) {
                    continue;
                }
                if (str_contains((string) file_get_contents($dosya), 'qrserver')) {
                    $elle[] = str_replace(base_path() . '/', '', $dosya);
                }
            }
        }

        $this->assertSame([], $elle, "QR adresi elle kurulmus: " . implode(', ', $elle));
    }

    /** @return array<int,string> */
    private function dosyalar(string $kok): array
    {
        $bulunan = [];
        $gezgin = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($kok));

        foreach ($gezgin as $dosya) {
            if ($dosya->isFile() && $dosya->getExtension() === 'php') {
                $bulunan[] = $dosya->getPathname();
            }
        }

        return $bulunan;
    }
}
