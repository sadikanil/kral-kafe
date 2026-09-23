<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockPhoto;
use App\Models\StockRecord;
use App\Models\DiscrepancyLog;
use App\Services\OpenAIStockAnalyzer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StockController extends Controller
{
    protected OpenAIStockAnalyzer $analyzer;

    public function __construct(OpenAIStockAnalyzer $analyzer)
    {
        $this->analyzer = $analyzer;
    }

    /**
     * Stok sayfasi (Dalga 29): urunler, konum etiketi ve stok; filtrelenir
     * ve toplu girilir.
     */
    public function index(Request $request)
    {
        $filtre = $request->validate([
            'konum' => ['nullable', 'integer'],
            'durum' => ['nullable', 'in:critical,out,ok,untracked'],
        ]);

        $urunler = Product::with('location')
            ->when($filtre['konum'] ?? null, fn ($q, $konum) => $q->where('location_id', $konum))
            ->when($filtre['durum'] ?? null, fn ($q, $durum) => $q->withStockStatus($durum))
            ->orderBy('category')->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.stock.index', [
            'products' => $urunler,
            'locations' => Location::tags(),
            'filter' => $filtre,
            'criticalCount' => Product::withStockStatus('critical')->count(),
        ]);
    }

    /**
     * Toplu stok girisi. Yalnizca gonderilen urunler degisir; bos stok =
     * takip kapali. Kritik stok bildirimi Product'in kendi olayindan.
     */
    public function update(Request $request)
    {
        $veri = $request->validate([
            'stok' => ['required', 'array'],
            'stok.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'stok.*.critical' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], ['stok.*.quantity.min' => 'Stok eksi olamaz.', 'stok.*.critical.min' => 'Kritik sayı eksi olamaz.']);

        $hatalar = [];
        foreach ($veri['stok'] as $id => $satir) {
            if (($satir['critical'] ?? null) !== null && ($satir['quantity'] ?? null) === null) {
                $hatalar["stok.{$id}.critical"] = 'Kritik sayı için önce stok girin.';
            }
        }
        if ($hatalar !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($hatalar);
        }

        $degisen = 0;
        foreach (Product::whereIn('id', array_keys($veri['stok']))->get() as $urun) {
            $satir = $veri['stok'][$urun->id];
            $urun->fill([
                'stock_quantity' => $satir['quantity'] ?? null,
                'critical_quantity' => $satir['critical'] ?? null,
            ]);

            if ($urun->isDirty()) {
                $urun->save();
                $degisen++;
            }
        }

        return back()->with('success', "{$degisen} ürünün stoğu güncellendi.");
    }

    /**
     * Sayim sayfasi: konum etiketi secilip fotografla (yapay zeka) sayilir;
     * cozulmemis tutarsizliklar ve son kayitlar burada.
     */
    public function counts()
    {
        return view('admin.stock.counts', [
            'locations' => Location::where('qr_code', '!=', Location::SELF_SERVICE_QR)
                ->whereHas('products')
                ->withCount('products')
                ->orderBy('name')
                ->get(),
            'unresolvedDiscrepancies' => DiscrepancyLog::with('location', 'product')
                ->where('resolved', false)
                ->orderBy('created_at', 'desc')
                ->get(),
            // Kaydeden yonetici 'admin' iliskisi (admin_id); 'recorder' diye
            // bir iliski yok, ilk kayittan sonra sayfa 500 veriyordu.
            'recentRecords' => StockRecord::with('location', 'product', 'admin')
                ->orderBy('recorded_at', 'desc')
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * Show stock capture form for a location.
     */
    public function capture(Location $location, Request $request)
    {
        $recordType = $request->get('type', 'opening');

        $location->load('products');

        return view('admin.stock.capture', [
            'location' => $location,
            'recordType' => $recordType,
        ]);
    }

    /**
     * Upload and process stock photos.
     */
    public function uploadPhotos(Request $request, Location $location)
    {
        $validated = $request->validate([
            'record_type' => ['required', 'in:opening,closing'],
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['image', 'max:10240'], // 10MB max per photo
        ]);

        $batchId = (string) Str::uuid();
        $uploadedPhotos = [];

        foreach ($request->file('photos') as $photo) {
            $path = $photo->store('stock_photos/' . $location->id, config('filesystems.uploads'));

            $stockPhoto = StockPhoto::create([
                'location_id' => $location->id,
                'batch_id' => $batchId,
                'record_type' => $validated['record_type'],
                'photo_path' => $path,
                'admin_id' => Auth::id(),
            ]);

            $uploadedPhotos[] = $stockPhoto;
        }

        return redirect()->route('admin.stock.analyze', [
            'location' => $location,
            'batch' => $batchId,
        ])->with('success', count($uploadedPhotos) . ' fotoğraf yüklendi. Analiz başlatılıyor...');
    }

    /**
     * Analyze photos with AI.
     */
    public function analyze(Location $location, string $batch)
    {
        $photos = StockPhoto::where('location_id', $location->id)
            ->where('batch_id', $batch)
            ->get();

        if ($photos->isEmpty()) {
            return redirect()->route('admin.stock.capture', $location)
                ->with('error', 'Fotoğraflar bulunamadı.');
        }

        // Bu konum etiketini tasiyan urunler; beklenen = sistemdeki stok
        // (takip kapaliysa null - ilk sayim takibi baslatir).
        $expectedProducts = $location->products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'expected_quantity' => $product->stock_quantity,
            ];
        })->toArray();

        // Her fotograf bir kez analiz edilir; birlesik sonuc bu sonuclardan
        // uretilir, fotograflar API'ye ikinci kez gonderilmez.
        $disk = Storage::disk(config('filesystems.uploads'));
        $allResults = [];

        foreach ($photos as $photo) {
            // Zaten analiz edilmis fotografi yeniden gondermeyiz: sayfa yenileme,
            // geri tusu ve dogrulama hatasi sonrasi donus her seferinde yeniden
            // faturalandiriyordu.
            if ($photo->processed_at && $photo->ai_analysis) {
                $allResults[] = $photo->ai_analysis;
                continue;
            }

            if (! $disk->exists($photo->photo_path)) {
                continue;
            }

            $result = $this->analyzer->analyzeStockPhoto(
                $disk->get($photo->photo_path),
                $disk->mimeType($photo->photo_path) ?: 'image/jpeg',
                $expectedProducts
            );

            $photo->update([
                'ai_analysis' => $result,
                'processed_at' => now(),
            ]);

            $allResults[] = $result;
        }

        $mergedResult = $this->analyzer->mergeResults($allResults);

        return view('admin.stock.review', [
            'location' => $location,
            'photos' => $photos,
            'batchId' => $batch,
            'analysisResult' => $mergedResult,
            'expectedProducts' => $expectedProducts,
        ]);
    }

    /**
     * Confirm stock counts after admin review.
     */
    public function confirm(Request $request, Location $location)
    {
        $validated = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'record_type' => ['required', 'in:opening,closing'],
            'products' => ['required', 'array'],
            'products.*.product_id' => ['required', 'exists:products,id'],
            'products.*.verified_quantity' => ['required', 'integer', 'min:0'],
            'products.*.ai_suggested_quantity' => ['nullable', 'integer', 'min:0'],
            'products.*.ai_confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'products.*.notes' => ['nullable', 'string'],
        ]);

        $records = [];

        foreach ($validated['products'] as $productData) {
            // Create stock record
            $record = StockRecord::create([
                'location_id' => $location->id,
                'product_id' => $productData['product_id'],
                'record_type' => $validated['record_type'],
                'verified_quantity' => $productData['verified_quantity'],
                'ai_suggested_quantity' => $productData['ai_suggested_quantity'] ?? null,
                'ai_confidence' => $productData['ai_confidence'] ?? null,
                'admin_id' => Auth::id(),
                'notes' => $productData['notes'] ?? null,
            ]);

            $records[] = $record;

            // Yalnizca bu konumun urunu guncellenir: formdan baska bir
            // urun kimligi gelse de baska konumun stoku bozulmaz.
            $urun = $location->products()->whereKey($productData['product_id'])->first();

            if ($urun) {
                $sayilan = (int) $productData['verified_quantity'];

                // Takip kapaliyken fark yoktur: ilk sayim takibi baslatir.
                if ($urun->tracksStock() && $urun->stock_quantity !== $sayilan) {
                    DiscrepancyLog::create([
                        'location_id' => $location->id,
                        'product_id' => $urun->id,
                        'expected_quantity' => $urun->stock_quantity,
                        'actual_quantity' => $sayilan,
                        'record_type' => $validated['record_type'],
                    ]);
                }

                // Sayilan, sistemdeki stok olur (kritik uyarisi olaydan).
                $urun->update(['stock_quantity' => $sayilan]);
            }
        }

        $typeText = $validated['record_type'] === 'opening' ? 'Açılış' : 'Kapanış';

        return redirect()->route('admin.stock.counts')
            ->with('success', "{$typeText} stok sayımı başarıyla kaydedildi.");
    }

    /**
     * Show discrepancy details.
     */
    public function discrepancy(DiscrepancyLog $discrepancy)
    {
        $discrepancy->load('location', 'product', 'resolver');

        return view('admin.stock.discrepancy', [
            'discrepancy' => $discrepancy,
        ]);
    }

    /**
     * Resolve a discrepancy.
     */
    public function resolveDiscrepancy(Request $request, DiscrepancyLog $discrepancy)
    {
        $validated = $request->validate([
            'resolution_notes' => ['required', 'string'],
        ]);

        // Zaten cozulmusse not ezilmez; yonetici mevcut notu gorsun diye
        // tutarsizlik sayfasina doner.
        if (! $discrepancy->resolve(Auth::user(), $validated['resolution_notes'])) {
            return redirect()->route('admin.stock.discrepancy', $discrepancy)
                ->with('error', 'Bu tutarsızlık zaten çözümlenmiş; not değiştirilemez.');
        }

        return redirect()->route('admin.stock.counts')
            ->with('success', 'Tutarsızlık çözümlendi.');
    }
}
