<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackagePeriod;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Paket katalogu. Fiyat degisikligi mevcut abonelikleri DEGISTIRMEZ.
 */
class PackageController extends Controller
{
    public function index()
    {
        return view('admin.packages.index', [
            'packages' => Package::withCount('subscriptions')->with('items.product')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.packages.create', $this->formVerisi(null));
    }

    public function store(Request $request)
    {
        [$veri, $kalemler] = $this->dogrula($request);

        DB::transaction(function () use ($veri, $kalemler) {
            $paket = Package::create($veri);
            $this->kalemleriYaz($paket, $kalemler);
        });

        return redirect()->route('admin.packages.index')->with('success', 'Paket oluşturuldu.');
    }

    public function edit(Package $package)
    {
        return view('admin.packages.edit', $this->formVerisi($package));
    }

    public function update(Request $request, Package $package)
    {
        [$veri, $kalemler] = $this->dogrula($request);

        DB::transaction(function () use ($package, $veri, $kalemler) {
            $package->update($veri);
            $this->kalemleriYaz($package, $kalemler);
        });

        return redirect()->route('admin.packages.index')->with('success', 'Paket güncellendi.');
    }

    public function toggleStatus(Package $package)
    {
        $package->update(['is_active' => ! $package->is_active]);

        return back()->with('success', 'Paket durumu güncellendi.');
    }

    /** @return array<string,mixed> */
    private function formVerisi(?Package $paket): array
    {
        return [
            'package' => $paket,
            'products' => Product::where('is_active', true)->orderBy('name')->get(),
            'items' => $paket ? $paket->items->keyBy('product_id') : collect(),
        ];
    }

    /**
     * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>}
     */
    private function dogrula(Request $request): array
    {
        $veri = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'monthly_price' => ['required', 'numeric', 'min:0', 'max:999999'],
            'description' => ['nullable', 'string', 'max:255'],
            'weekly_mock_exams' => ['nullable', 'integer', 'min:0', 'max:20'],
            'items' => ['nullable', 'array'],
            'items.*.included' => ['nullable', 'boolean'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.period' => ['nullable', Rule::enum(PackagePeriod::class)],
        ]);

        $kalemler = [];
        foreach ($veri['items'] ?? [] as $urunId => $kalem) {
            if (empty($kalem['included'])) {
                continue;
            }
            $kalemler[(int) $urunId] = [
                'included_quantity' => isset($kalem['quantity']) && $kalem['quantity'] !== '' ? (int) $kalem['quantity'] : null,
                'period' => $kalem['period'] ?? PackagePeriod::Monthly->value,
            ];
        }

        return [[
            'name' => $veri['name'],
            'monthly_price' => $veri['monthly_price'],
            'description' => $veri['description'] ?? null,
            'weekly_mock_exams' => (int) ($veri['weekly_mock_exams'] ?? 0),
            'has_reserved_table' => $request->boolean('has_reserved_table'),
            'includes_coaching' => $request->boolean('includes_coaching'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
        ], $kalemler];
    }

    /** @param array<int,array<string,mixed>> $kalemler */
    private function kalemleriYaz(Package $paket, array $kalemler): void
    {
        $gecerli = Product::whereIn('id', array_keys($kalemler))->pluck('id')->all();

        $paket->items()->whereNotIn('product_id', $gecerli)->delete();

        foreach ($gecerli as $urunId) {
            $paket->items()->updateOrCreate(['product_id' => $urunId], $kalemler[$urunId]);
        }
    }
}
