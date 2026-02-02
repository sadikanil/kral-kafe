<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
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
            $query->where('name', 'like', "%{$search}%");
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
            'category' => $validated['category'],
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
        ]);

        $product->update([
            'name' => $validated['name'],
            'emoji' => $validated['emoji'] ?? null,
            'category' => $validated['category'],
            'unit_price' => $validated['unit_price'],
            'unit_type' => $validated['unit_type'],
            'is_active' => $request->boolean('is_active'),
        ]);

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
            Storage::disk('public')->delete($product->image_url);
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
