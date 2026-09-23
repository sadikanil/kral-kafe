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
        $query = Product::with('location');

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
    public function create()
    {
        return view('admin.products.create', $this->formData(new Product()));
    }

    /**
     * Store a newly created product.
     */
    public function store(Request $request)
    {
        Product::create($this->validatedProduct($request, null));

        return redirect()->route('admin.products.index')
            ->with('success', 'Ürün başarıyla oluşturuldu.');
    }

    /**
     * Show the form for editing a product.
     */
    public function edit(Product $product)
    {
        return view('admin.products.edit', $this->formData($product));
    }

    /**
     * Update the specified product.
     */
    public function update(Request $request, Product $product)
    {
        $product->update($this->validatedProduct($request, $product));

        return redirect()->route('admin.products.index')
            ->with('success', 'Ürün başarıyla güncellendi.');
    }

    /** @return array<string,mixed> */
    private function formData(Product $product): array
    {
        return [
            'product' => $product,
            'categories' => Product::distinct()->pluck('category')->filter(),
            'units' => Product::distinct()->pluck('unit_type')->filter(),
            // Dalga 29: konum bir etiket; Lokasyonlar sayfasi kalkti.
            'locations' => \App\Models\Location::tags(),
        ];
    }

    /**
     * Ekleme ve duzenleme ayni kurallar (Dalga 29). Iki ayri kopya
     * ayristigi icin aciklama hic kaydedilmiyordu.
     *
     * @return array<string,mixed>
     */
    private function validatedProduct(Request $request, ?Product $product): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'emoji' => ['nullable', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'unit_type' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'new_location' => ['nullable', 'string', 'max:100'],
            'stock_quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'critical_quantity' => ['nullable', 'integer', 'min:0', 'max:100000',
                'prohibited_if:stock_quantity,null'],
        ], [
            'stock_quantity.min' => 'Stok eksi olamaz.',
            'critical_quantity.prohibited_if' => 'Kritik sayı için önce stok girin.',
        ]);

        $konum = filled($v['new_location'] ?? null)
            ? \App\Models\Location::tagNamed($v['new_location'])->id
            : ($v['location_id'] ?? null);

        return [
            'name' => $v['name'],
            'emoji' => $v['emoji'] ?? null,
            'category' => $v['category'] ?? null,
            'unit_price' => $v['unit_price'],
            'unit_type' => $v['unit_type'],
            'description' => $v['description'] ?? null,
            // Alan hic gelmezse durum DEGISMEZ: eski duzenleme formunda kutu
            // yoktu ve her kayit urunu sessizce pasife aliyordu.
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : ($product?->is_active ?? true),
            'location_id' => $konum,
            'stock_quantity' => $v['stock_quantity'] ?? null,
            'critical_quantity' => $v['critical_quantity'] ?? null,
        ];
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
}
