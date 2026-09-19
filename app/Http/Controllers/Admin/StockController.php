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
     * Show stock management dashboard.
     */
    public function index()
    {
        $locations = Location::where('is_active', true)
            ->with('products')
            ->get();

        // Unresolved discrepancies
        $unresolvedDiscrepancies = DiscrepancyLog::with('location', 'product')
            ->where('resolved', false)
            ->orderBy('created_at', 'desc')
            ->get();

        // Recent stock records
        $recentRecords = StockRecord::with('location', 'product', 'recorder')
            ->orderBy('recorded_at', 'desc')
            ->limit(20)
            ->get();

        return view('admin.stock.index', [
            'locations' => $locations,
            'unresolvedDiscrepancies' => $unresolvedDiscrepancies,
            'recentRecords' => $recentRecords,
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

        // Get expected products
        $expectedProducts = $location->products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'expected_quantity' => $product->pivot->expected_quantity,
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

            // Check for discrepancies
            $productLocation = $location->productLocations()
                ->where('product_id', $productData['product_id'])
                ->first();

            if ($productLocation) {
                $expectedQty = $productLocation->expected_quantity;
                $actualQty = $productData['verified_quantity'];

                if (abs($expectedQty - $actualQty) > 0) {
                    DiscrepancyLog::create([
                        'location_id' => $location->id,
                        'product_id' => $productData['product_id'],
                        'expected_quantity' => $expectedQty,
                        'actual_quantity' => $actualQty,
                        'record_type' => $validated['record_type'],
                    ]);
                }

                // Update expected quantity for next time
                $productLocation->update([
                    'expected_quantity' => $actualQty,
                ]);
            }
        }

        $typeText = $validated['record_type'] === 'opening' ? 'Açılış' : 'Kapanış';

        return redirect()->route('admin.stock.index')
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

        $discrepancy->resolve(Auth::user(), $validated['resolution_notes']);

        return redirect()->route('admin.stock.index')
            ->with('success', 'Tutarsızlık çözümlendi.');
    }
}
