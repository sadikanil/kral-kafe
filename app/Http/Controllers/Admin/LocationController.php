<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * Display a listing of locations.
     */
    public function index()
    {
        $locations = Location::withCount('products')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.locations.index', [
            'locations' => $locations,
        ]);
    }

    /**
     * Show the form for creating a new location.
     */
    public function create()
    {
        $products = Product::where('is_active', true)->orderBy('name')->get();
        return view('admin.locations.create', ['products' => $products]);
    }

    /**
     * Store a newly created location.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:shelf,cabinet,fridge'],
            'description' => ['nullable', 'string'],
            'products' => ['nullable', 'array'],
            'products.*' => ['exists:products,id'],
        ]);

        $location = Location::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'description' => $validated['description'],
            'is_active' => true,
        ]);

        // Attach products (simple ID array from checkboxes)
        if (!empty($validated['products'])) {
            foreach ($validated['products'] as $productId) {
                ProductLocation::create([
                    'location_id' => $location->id,
                    'product_id' => $productId,
                    'expected_quantity' => 0,
                    'min_quantity' => 0,
                ]);
            }
        }

        return redirect()->route('admin.locations.index')
            ->with('success', 'Lokasyon başarıyla oluşturuldu.');
    }

    /**
     * Display the specified location.
     */
    public function show(Location $location)
    {
        $location->load('products', 'stockRecords.product', 'stockRecords.admin');

        return view('admin.locations.show', [
            'location' => $location,
        ]);
    }

    /**
     * Show the form for editing a location.
     */
    public function edit(Location $location)
    {
        $products = Product::where('is_active', true)->orderBy('name')->get();
        $location->load('productLocations');

        return view('admin.locations.edit', [
            'location' => $location,
            'products' => $products,
        ]);
    }

    /**
     * Update the specified location.
     */
    public function update(Request $request, Location $location)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:shelf,cabinet,fridge'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'products' => ['nullable', 'array'],
            'products.*' => ['exists:products,id'],
        ]);

        $location->update([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'description' => $validated['description'],
            'is_active' => $request->boolean('is_active'),
        ]);

        // Get existing product data to preserve expected_quantity
        $existingProducts = $location->productLocations()
            ->pluck('expected_quantity', 'product_id')
            ->toArray();

        // Sync products
        $location->productLocations()->delete();
        if (!empty($validated['products'])) {
            foreach ($validated['products'] as $productId) {
                ProductLocation::create([
                    'location_id' => $location->id,
                    'product_id' => $productId,
                    'expected_quantity' => $existingProducts[$productId] ?? 0,
                    'min_quantity' => 0,
                ]);
            }
        }

        return redirect()->route('admin.locations.index')
            ->with('success', 'Lokasyon başarıyla güncellendi.');
    }

    /**
     * Remove the specified location.
     */
    public function destroy(Location $location)
    {
        $location->delete();

        return redirect()->route('admin.locations.index')
            ->with('success', 'Lokasyon başarıyla silindi.');
    }

    /**
     * Toggle location status.
     */
    public function toggleStatus(Location $location)
    {
        $location->update([
            'is_active' => !$location->is_active
        ]);

        return back()->with('success', 'Lokasyon durumu güncellendi.');
    }
}
