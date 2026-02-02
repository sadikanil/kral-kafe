<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\Consumption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConsumptionController extends Controller
{
    /**
     * Show consumption page for a specific location (QR landing page).
     */
    public function showLocation(string $qrCode)
    {
        $location = Location::where('qr_code', $qrCode)
            ->where('is_active', true)
            ->firstOrFail();

        // Get products at this location
        $products = $location->products()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Get user's recent consumptions for undo feature
        $recentConsumptions = [];
        if (Auth::check()) {
            $recentConsumptions = Consumption::with('product')
                ->where('user_id', Auth::id())
                ->where('location_id', $location->id)
                ->where('is_undone', false)
                ->where('consumed_at', '>=', now()->subMinutes(1))
                ->orderBy('consumed_at', 'desc')
                ->get();
        }

        return view('user.consume', [
            'location' => $location,
            'products' => $products,
            'recentConsumptions' => $recentConsumptions,
        ]);
    }

    /**
     * Store a new consumption.
     */
    /**
     * Store a new consumption (Bulk).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:10',
        ]);

        $user = Auth::user();

        // Check subscription status
        if (!$user->hasActiveSubscription()) {
            return response()->json([
                'success' => false,
                'message' => 'Aboneliğiniz aktif değil. Lütfen yönetici ile iletişime geçin.',
            ], 403);
        }

        $locationId = $validated['location_id'];
        $items = $validated['items'];
        $savedConsumptions = [];
        $batchId = uniqid('batch_', true); // Simple batch ID for this transaction

        // Use transaction to ensure data integrity
        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $locationId, $items, $batchId, &$savedConsumptions) {
            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);

                // Create consumption record
                // Ideally we should add a 'batch_id' column to consumptions table for easier grouping
                // For now, we rely on the timestamp or ID list returned

                $consumption = Consumption::create([
                    'user_id' => $user->id,
                    'location_id' => $locationId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->unit_price,
                    // 'batch_id' => $batchId, // TODO: Add migration for this later if needed
                ]);

                // Update Stock (ProductLocation)
                $productLocation = \App\Models\ProductLocation::where('location_id', $locationId)
                    ->where('product_id', $item['product_id'])
                    ->first();

                if ($productLocation) {
                    $productLocation->decrement('expected_quantity', $item['quantity']);
                } else {
                    // product_locations tablosunda kayıt yoksa oluşturalım mı?
                    // Genelde stok sayımı ile oluşturulur ama eksiye düşmesine izin verelim şimdilik (veya 0'dan başlatalım)
                    \App\Models\ProductLocation::create([
                        'location_id' => $locationId,
                        'product_id' => $item['product_id'],
                        'expected_quantity' => -$item['quantity'], // Negative stock indicates missing/consumed without stock record
                        'min_quantity' => 0
                    ]);
                }

                $savedConsumptions[] = $consumption;
            }
        });

        // Calculate total for response
        $totalAmount = 0;
        $consumptionIds = [];
        foreach ($savedConsumptions as $c) {
            $totalAmount += $c->total_price;
            $consumptionIds[] = $c->id;
        }

        return response()->json([
            'success' => true,
            'message' => 'Tüketim kaydedildi!',
            'consumption' => [
                'batch_ids' => $consumptionIds, // Return all IDs to undo them all
                'count' => count($savedConsumptions),
                'total' => number_format($totalAmount, 2, ',', '.') . ' ₺',
                'can_undo' => true,
                'undo_seconds' => 60,
            ],
        ]);
    }

    /**
     * Undo a consumption (Single or Batch).
     * Now accepts an array of IDs in request or a single ID in route.
     * To keep it simple with existing route structure, we will expect a POST body with 'ids' for batch undo,
     * or fallback to single ID from route binding if 'ids' is missing.
     */
    public function undo(Request $request, $id = null)
    {
        $ids = $request->input('ids');

        // Support legacy single ID undo via route parameter
        if (empty($ids) && $id) {
            $ids = [$id];
        }

        if (empty($ids)) {
            return response()->json([
                'success' => false,
                'message' => 'Geri alınacak kayıt bulunamadı.',
            ], 400);
        }

        $restoredCount = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($ids, &$restoredCount) {
            $consumptions = Consumption::whereIn('id', $ids)->get();

            foreach ($consumptions as $consumption) {
                // Check ownership
                if ($consumption->user_id !== Auth::id()) {
                    continue; // Skip, don't error out entire batch
                }

                // Check if can undo
                if (!$consumption->canUndo()) {
                    continue;
                }

                // Undo: Mark as undone
                if ($consumption->undo()) {
                    // Restore Stock
                    $productLocation = \App\Models\ProductLocation::where('location_id', $consumption->location_id)
                        ->where('product_id', $consumption->product_id)
                        ->first();

                    if ($productLocation) {
                        $productLocation->increment('expected_quantity', $consumption->quantity);
                    }

                    $restoredCount++;
                }
            }
        });

        if ($restoredCount > 0) {
            return response()->json([
                'success' => true,
                'message' => "Tüketim geri alındı ($restoredCount parça).",
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Geri alma süresi doldu veya yetkiniz yok.',
            ], 400);
        }
    }

    /**
     * Get user's current month summary (AJAX).
     */
    public function getCurrentMonthSummary()
    {
        $user = Auth::user();

        return response()->json([
            'total_amount' => number_format($user->getCurrentMonthTotal(), 2, ',', '.') . ' ₺',
            'total_items' => $user->getCurrentMonthItemCount(),
        ]);
    }
}
