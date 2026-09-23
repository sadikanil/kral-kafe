<?php

namespace Tests\Feature;

use App\Models\Consumption;
use App\Models\DiscrepancyLog;
use App\Models\Location;
use App\Models\MonthlyBill;
use App\Models\Product;
use App\Models\StockPhoto;
use App\Models\User;
use App\Services\OpenAIStockAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockAndReportHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'subscription_status' => 'active']);
    }

    private function location(): Location
    {
        return Location::create([
            'name' => 'Raf', 'type' => 'shelf', 'qr_code' => 'LOC-H', 'is_active' => true,
        ]);
    }

    private function product(): Product
    {
        return Product::create(['name' => 'Kola', 'unit_price' => 20, 'unit_type' => 'paket']);
    }

    /** Dalga 29: tutarsizliklar Urunler > Sayim sekmesinde. */
    public function test_the_count_page_shows_the_discrepancy_difference(): void
    {
        DiscrepancyLog::create([
            'location_id' => $this->location()->id,
            'product_id' => $this->product()->id,
            'expected_quantity' => 10,
            'actual_quantity' => 6,
            'record_type' => 'closing',
            'detected_at' => '2026-03-01 09:00:00',
            'resolved' => false,
        ]);

        // difference = 6 - 10 = -4 ; sayfa bu degeri gostermeli
        $this->actingAs($this->admin())
            ->get(route('admin.stock.counts'))
            ->assertOk()
            ->assertSee('<td>-4</td>', false);
    }

    public function test_resolution_notes_validation_message_is_in_turkish(): void
    {
        $log = DiscrepancyLog::create([
            'location_id' => $this->location()->id,
            'product_id' => $this->product()->id,
            'expected_quantity' => 10, 'actual_quantity' => 6,
            'record_type' => 'closing', 'detected_at' => '2026-03-01 09:00:00', 'resolved' => false,
        ]);

        $this->actingAs($this->admin())
            ->from("/yonetim/stok/tutarsizlik/{$log->id}")
            ->post("/yonetim/stok/tutarsizlik/{$log->id}/coz", ['resolution_notes' => ''])
            ->assertSessionHasErrors(['resolution_notes' => 'çözüm notu alanı gereklidir.']);
    }

    public function test_reopening_the_analysis_page_does_not_call_the_api_again(): void
    {
        config(['filesystems.uploads' => 'yukleme', 'services.openai.api_key' => 'anahtar']);
        Storage::fake('yukleme');
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'products_detected' => [], 'anomalies' => [], 'overall_confidence' => 0.8, 'summary' => 'ok',
                ])]]],
            ]),
        ]);

        $admin = $this->admin();
        $location = $this->location();
        $this->product()->update(['location_id' => $location->id, 'stock_quantity' => 5, 'critical_quantity' => 1]);

        $this->actingAs($admin)->post("/yonetim/stok/{$location->id}/yukle", [
            'record_type' => 'opening',
            'photos' => [UploadedFile::fake()->image('a.jpg')],
        ]);
        $batch = StockPhoto::sole()->batch_id;

        $this->actingAs($admin)->get("/yonetim/stok/{$location->id}/analiz/{$batch}")->assertOk();
        $ilkSayim = count(Http::recorded());

        // Sayfa yenilendiginde fotograf yeniden analiz edilmemeli - her yenileme
        // OpenAI'ye yeniden fatura cikariyordu.
        $this->actingAs($admin)->get("/yonetim/stok/{$location->id}/analiz/{$batch}")->assertOk();

        $this->assertSame($ilkSayim, count(Http::recorded()),
            'Analiz sayfasi her acilisinda fotograflari yeniden API\'ye gonderiyor');
    }

    public function test_identical_anomalies_from_several_photos_are_not_repeated(): void
    {
        $sonuc = [
            'success' => true,
            'products_detected' => [],
            'anomalies' => [['type' => 'empty_shelf', 'description' => 'Raf bos']],
            'overall_confidence' => 0.8,
        ];

        $merged = (new OpenAIStockAnalyzer())->mergeResults([$sonuc, $sonuc, $sonuc]);

        $this->assertCount(1, $merged['anomalies'],
            'Ayni anomali her fotograf icin tekrar listeleniyor');
    }

    public function test_user_report_survives_a_nonsense_period_in_the_url(): void
    {
        $customer = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->admin())
            ->get("/yonetim/raporlar/kullanici/{$customer->id}?year=abc&month=99")
            ->assertOk();
    }

    public function test_monthly_report_links_carry_the_selected_period(): void
    {
        $customer = User::factory()->create(['role' => 'student']);
        MonthlyBill::create([
            'user_id' => $customer->id, 'bill_month' => '2026-03-01',
            'total_items' => 3, 'total_amount' => 60, 'status' => 'pending',
        ]);

        $html = $this->actingAs($this->admin())
            ->get('/yonetim/raporlar/aylik?year=2026&month=3')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/raporlar\/kullanici\/' . $customer->id . '\?[^"]*year=2026[^"]*month=3/',
            $html,
            'Kullanici raporu baglantisi secili donemi tasimiyor'
        );
    }
}
