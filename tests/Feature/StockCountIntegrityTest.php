<?php

namespace Tests\Feature;

use App\Models\DiscrepancyLog;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockPhoto;
use App\Models\StockRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stok sayimi akisinin butunlugu (QA faz 2, grup D).
 *
 * Duman testleri (AdminCafeSmokeTest) sirali istekleri kapsiyor; burada
 * yarisan istekler ve Dalga 29'da kaldirilan iliski/alanlara takilan
 * ekranlar var.
 */
class StockCountIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(string $ad = 'Yönetici Ali'): User
    {
        return User::factory()->admin()->create(['name' => $ad]);
    }

    private function tutarsizlik(): DiscrepancyLog
    {
        $dolap = Location::firstOrCreate(['name' => 'Buzdolabı'], ['type' => 'fridge', 'is_active' => true]);
        $kola = Product::create([
            'name' => 'Coca-Cola', 'unit_price' => 25, 'unit_type' => 'Şişe',
            'location_id' => $dolap->id, 'stock_quantity' => 18,
        ]);

        return DiscrepancyLog::create([
            'location_id' => $dolap->id, 'product_id' => $kola->id,
            'expected_quantity' => 18, 'actual_quantity' => 15, 'record_type' => 'closing',
        ]);
    }

    // --- Tutarsizlik yalnizca bir kez cozulur (hata 42) -----------------------

    /**
     * Iki istek ayni anda gelir: ikisi de kaydi "cozulmemis" okur. Kontrol
     * PHP'de degil UPDATE'in kosulunda olmali; yoksa ikinci yazan ilkini ezer.
     */
    public function test_two_racing_resolves_keep_the_first_note(): void
    {
        $fark = $this->tutarsizlik();
        $ali = $this->yonetici('Yönetici Ali');
        $veli = $this->yonetici('Yönetici Veli');

        // Ayni satirin iki bayat kopyasi: iki ayri istegin bellekteki hali.
        $birinci = DiscrepancyLog::find($fark->id);
        $ikinci = DiscrepancyLog::find($fark->id);

        $this->assertTrue($birinci->resolve($ali, 'Kırık şişe'));
        $this->assertFalse($ikinci->resolve($veli, 'Sayım hatası'));

        $fark->refresh();
        $this->assertTrue($fark->resolved);
        $this->assertSame('Kırık şişe', $fark->resolution_notes);
        $this->assertSame($ali->id, $fark->resolved_by);

        // Kaybeden kopya da veritabanindaki gercegi gosterir.
        $this->assertSame('Kırık şişe', $ikinci->resolution_notes);
    }

    /**
     * Fotograf formundaki "Notlar" kutusu hic kaydedilmiyordu (stock_photos'ta
     * sutunu yok, uploadPhotos okumuyor): yazilan not sessizce kayboluyordu.
     * Urun basina not inceleme adiminda var ve kayda isleniyor.
     */
    public function test_the_capture_form_only_sends_fields_the_upload_keeps(): void
    {
        $dolap = Location::firstOrCreate(['name' => 'Buzdolabı'], ['type' => 'fridge', 'is_active' => true]);
        Product::create(['name' => 'Coca-Cola', 'unit_price' => 25, 'unit_type' => 'Şişe', 'location_id' => $dolap->id]);

        $html = $this->actingAs($this->yonetici())->get(route('admin.stock.capture', $dolap))->assertOk()->getContent();

        preg_match_all('/<(?:input|select|textarea)\b[^>]*\bname="([^"]+)"/', $html, $eslesme);
        $alanlar = array_values(array_diff(array_unique($eslesme[1]), ['_token']));
        sort($alanlar);

        $this->assertSame(['photos[]', 'record_type'], $alanlar);
    }

    /**
     * A19: sayim telefonda, rafin onunde girilir. Adet kutusu rakam
     * klavyesini acmali; dunku sayim oneri olarak gelmemeli; tabloda gorunen
     * sutun basligi ekran okuyucuya kutunun adi olarak ulasmiyor.
     */
    public function test_the_review_form_opens_the_number_pad_and_names_each_box(): void
    {
        config(['filesystems.uploads' => 'yukleme']);
        Storage::fake('yukleme');
        $yonetici = $this->yonetici();
        $dolap = Location::firstOrCreate(['name' => 'Buzdolabı'], ['type' => 'fridge', 'is_active' => true]);
        $kola = Product::create(['name' => 'Coca-Cola', 'unit_price' => 25, 'unit_type' => 'Şişe', 'location_id' => $dolap->id, 'stock_quantity' => 18]);
        $parti = (string) Str::uuid();
        StockPhoto::create([
            'location_id' => $dolap->id, 'batch_id' => $parti, 'record_type' => 'closing',
            'photo_path' => "stock_photos/{$dolap->id}/raf.jpg", 'admin_id' => $yonetici->id, 'processed_at' => now(),
            'ai_analysis' => ['success' => true, 'summary' => 'ok', 'overall_confidence' => 0.9, 'products_detected' => [], 'anomalies' => []],
        ]);

        $html = $this->actingAs($yonetici)->get(route('admin.stock.analyze', [$dolap, $parti]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input\b[^>]*name="products\[' . $kola->id . '\]\[verified_quantity\]"[^>]*>/', $html);
        preg_match('/<input\b[^>]*name="products\[' . $kola->id . '\]\[verified_quantity\]"[^>]*>/', $html, $adet);
        $this->assertStringContainsString('inputmode="numeric"', $adet[0]);
        $this->assertStringContainsString('autocomplete="off"', $adet[0]);
        $this->assertStringContainsString('aria-label="Coca-Cola sayılan adet"', $adet[0]);

        preg_match('/<input\b[^>]*name="products\[' . $kola->id . '\]\[notes\]"[^>]*>/', $html, $not);
        $this->assertStringContainsString('aria-label="Coca-Cola notu"', $not[0]);
    }

    // --- Kaldirilan iliski/alan taramasi (hata 37'nin kardesleri) -------------

    /** @var list<string> */
    private array $ihlaller = [];

    protected function tearDown(): void
    {
        // Statik bayraklar ayni surecteki sonraki testlere tasinmasin.
        Model::preventAccessingMissingAttributes(false);
        Model::handleMissingAttributeViolationUsing(null);
        Model::preventLazyLoading(false);
        Model::handleLazyLoadingViolationUsing(null);

        parent::tearDown();
    }

    /**
     * Eloquent tanimsiz alani sessizce null dondurur: 'recorder' ve 'quantity'
     * boyle bos hucre olarak kaldi, 'recorder' eager load'da ancak 500 verdi.
     * Katı kip bu yanlislari yakalar; N+1 da ayni yoldan kaydedilir.
     * Istisna atmak yerine toplanir ki tek calistirmada hepsi gorunsun.
     */
    private function katiKip(): void
    {
        $this->ihlaller = [];

        Model::preventAccessingMissingAttributes();
        Model::handleMissingAttributeViolationUsing(function (Model $model, string $alan) {
            $this->ihlaller[] = 'tanimsiz alan: ' . $model::class . '::' . $alan;
        });

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $iliski) {
            $this->ihlaller[] = 'tembel yukleme (N+1): ' . $model::class . '::' . $iliski;
        });
    }

    public function test_every_stock_page_renders_without_touching_removed_relations_or_fields(): void
    {
        config(['filesystems.uploads' => 'yukleme']);
        Storage::fake('yukleme');

        $ali = $this->yonetici('Yönetici Ali');
        $veli = $this->yonetici('Yönetici Veli');

        $dolap = Location::firstOrCreate(['name' => 'Buzdolabı'], ['type' => 'fridge', 'is_active' => true]);
        $raf = Location::firstOrCreate(['name' => 'Aburcubur Rafı'], ['type' => 'shelf', 'is_active' => true]);
        $kola = Product::create(['name' => 'Coca-Cola', 'unit_price' => 25, 'unit_type' => 'Şişe', 'location_id' => $dolap->id, 'stock_quantity' => 15, 'critical_quantity' => 6]);
        $ayran = Product::create(['name' => 'Ayran', 'unit_price' => 15, 'unit_type' => 'Şişe', 'location_id' => $dolap->id]);
        $gofret = Product::create(['name' => 'Gofret', 'unit_price' => 12, 'unit_type' => 'Paket', 'location_id' => $raf->id, 'stock_quantity' => 2, 'critical_quantity' => 5]);

        // Birden cok satir: tembel yukleme ancak koleksiyonda gorunur.
        foreach ([[$dolap, $kola, $ali], [$dolap, $ayran, $veli], [$raf, $gofret, $ali]] as [$konum, $urun, $kim]) {
            StockRecord::create(['location_id' => $konum->id, 'product_id' => $urun->id, 'record_type' => 'closing', 'verified_quantity' => 7, 'admin_id' => $kim->id]);
        }
        $acik = DiscrepancyLog::create(['location_id' => $dolap->id, 'product_id' => $kola->id, 'expected_quantity' => 18, 'actual_quantity' => 15, 'record_type' => 'closing']);
        DiscrepancyLog::create(['location_id' => $raf->id, 'product_id' => $gofret->id, 'expected_quantity' => 1, 'actual_quantity' => 2, 'record_type' => 'opening']);
        $cozulen = DiscrepancyLog::create(['location_id' => $dolap->id, 'product_id' => $ayran->id, 'expected_quantity' => 3, 'actual_quantity' => 2, 'record_type' => 'closing']);
        $cozulen->resolve($veli, 'Bir şişe kırıldı');

        // Analiz edilmis fotograflar: sayfa API'yi yeniden cagirmaz.
        $parti = (string) Str::uuid();
        foreach (['sol.jpg', 'sag.jpg'] as $ad) {
            StockPhoto::create([
                'location_id' => $dolap->id, 'batch_id' => $parti, 'record_type' => 'closing',
                'photo_path' => "stock_photos/{$dolap->id}/{$ad}", 'admin_id' => $ali->id, 'processed_at' => now(),
                'ai_analysis' => [
                    'success' => true, 'summary' => 'ok', 'overall_confidence' => 0.9,
                    'products_detected' => [
                        ['product_id' => $kola->id, 'name' => 'Coca-Cola', 'estimated_quantity' => 14, 'confidence' => 0.9],
                        ['product_id' => null, 'name' => 'Fanta', 'estimated_quantity' => 3, 'confidence' => 0.4],
                    ],
                    'anomalies' => [['type' => 'low_stock', 'description' => 'Kola azalmış']],
                ],
            ]);
        }

        $sayfalar = [
            route('admin.stock.index'),
            route('admin.stock.index', ['durum' => 'critical']),
            route('admin.stock.counts'),
            route('admin.stock.capture', $dolap),
            route('admin.stock.analyze', [$dolap, $parti]),
            route('admin.stock.discrepancy', $acik),
            route('admin.stock.discrepancy', $cozulen),
        ];

        $this->actingAs($ali);
        $this->katiKip();

        foreach ($sayfalar as $adres) {
            $this->get($adres)->assertOk();
        }

        $this->assertSame([], array_values(array_unique($this->ihlaller)));
    }
}
