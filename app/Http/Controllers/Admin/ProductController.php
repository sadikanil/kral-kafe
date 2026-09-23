<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of products.
     */
    public function index(Request $request)
    {
        $query = Product::query();

        // Filter by category
        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        // Filter by status
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $like = SqlDialect::likeOperator(DB::connection()->getDriverName());
            $query->where('name', $like, "%{$search}%");
        }

        $products = $query->orderBy('name')->paginate(20);

        // Get categories for filter
        $categories = Product::distinct()->pluck('category')->filter();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => $categories,
        ]);
    }

    /**
     * Show the form for creating a new product.
     */
    /**
     * Show the form for creating a new product.
     */
    public function create()
    {
        $categories = Product::distinct()->pluck('category')->filter();
        $units = Product::distinct()->pluck('unit_type')->filter();

        return view('admin.products.create', [
            'categories' => $categories,
            'units' => $units
        ]);
    }

    /**
     * Store a newly created product.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'emoji' => ['nullable', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'unit_type' => ['required', 'string', 'max:50'],
        ]);

        Product::create([
            'name' => $validated['name'],
            'emoji' => $validated['emoji'] ?? null,
            'category' => $validated['category'] ?? null,
            'unit_price' => $validated['unit_price'],
            'unit_type' => $validated['unit_type'],
            // removed barcode and image_url
            'is_active' => true,
        ]);

        return redirect()->route('admin.products.index')
            ->with('success', 'Ürün başarıyla oluşturuldu.');
    }

    /**
     * Show the form for editing a product.
     */
    public function edit(Product $product)
    {
        $categories = Product::distinct()->pluck('category')->filter();
        $units = Product::distinct()->pluck('unit_type')->filter();

        return view('admin.products.edit', [
            'product' => $product,
            // Dalga 26: urun nerede, kac adet - yalnizca yoneticinin girdisi.
            'locations' => \App\Models\Location::where('is_active', true)->orderBy('name')->get(),
            'placements' => $product->productLocations()->pluck('expected_quantity', 'location_id'),
            'categories' => $categories,
            'units' => $units,
        ]);
    }

    /**
     * Update the specified product.
     */
    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'emoji' => ['nullable', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'unit_type' => ['required', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'places' => ['nullable', 'array'],
            'places.*.on' => ['nullable', 'boolean'],
            'places.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], ['places.*.quantity.min' => 'Stok eksi olamaz.']);

        $product->update([
            'name' => $validated['name'],
            'emoji' => $validated['emoji'] ?? null,
            'category' => $validated['category'] ?? null,
            'unit_price' => $validated['unit_price'],
            'unit_type' => $validated['unit_type'],
            'is_active' => $request->boolean('is_active'),
        ]);

        // Bolum formda yoksa yerlesime DOKUNULMAZ; varsa isaretli olanlar
        // kalir/guncellenir, isareti kaldirilanlar silinir.
        if ($request->has('places_present')) {
            $this->syncPlacements($product, $validated['places'] ?? []);
        }

        return redirect()->route('admin.products.index')
            ->with('success', 'Ürün başarıyla güncellendi.');
    }

    /**
     * Remove the specified product.
     */
    public function destroy(Product $product)
    {
        // Delete image if exists
        if ($product->image_url) {
            Storage::disk(config('filesystems.uploads'))->delete($product->image_url);
        }

        $product->delete();

        return redirect()->route('admin.products.index')
            ->with('success', 'Ürün başarıyla silindi.');
    }

    /**
     * Toggle product active status.
     */
    public function toggleStatus(Product $product)
    {
        $product->update(['is_active' => !$product->is_active]);

        $statusText = $product->is_active ? 'aktifleştirildi' : 'pasifleştirildi';

        return back()->with('success', "Ürün {$statusText}.");
    }

    /** @param array<int,array{on?:mixed,quantity?:mixed}> $yerler */
    private function syncPlacements(Product $product, array $yerler): void
    {
        $gecerli = \App\Models\Location::whereIn('id', array_keys($yerler))->pluck('id')->all();
        $isaretli = [];

        foreach ($yerler as $lokasyonId => $yer) {
            if (empty($yer['on']) || ! in_array((int) $lokasyonId, $gecerli, true)) {
                continue;
            }

            $isaretli[] = (int) $lokasyonId;
            \App\Models\ProductLocation::updateOrCreate(
                ['product_id' => $product->id, 'location_id' => (int) $lokasyonId],
                ['expected_quantity' => (int) ($yer['quantity'] ?? 0), 'min_quantity' => 0],
            );
        }

        $product->productLocations()->whereNotIn('location_id', $isaretli)->delete();
    }
}
