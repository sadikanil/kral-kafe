<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Consumption;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Services\PackageCoverage;
use App\Support\LocalDay;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Self adisyon: ogrenci QR okutmadan, panelden, sistemde tanimli bir urunu
 * kendi hesabina ekler.
 *
 * Dalga 8'de QR tuketim akisi KALKTI ve iki isi devraldi:
 *
 *  1. STOK DUSUMU. Stok mutabakati expected_quantity'nin sayimlar ARASINDA
 *     tuketimle dusmesine dayaniyor; stogu dusen tek yer QR akisiydi. Self
 *     adisyon stoga dokunmasaydi kapanis sayimi her gun mesru satisi KAYIP
 *     olarak isaretlerdi.
 *  2. PAKET KAPSAMI. package_items Dalga 7'den beri yalnizca tanimdi.
 *
 * Kullanicinin istegi QR'in TUKETIM icin okutulmamasiydi; lokasyon QR'lari
 * stok sayimi tarafinda duruyor.
 */
class TabController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        [$gunBasi, $gunSonu] = LocalDay::bounds(LocalDay::today());

        $urunler = Product::where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return view('user.tab', [
            'products' => $urunler,
            // Urun basina secilebilir raflar. Yalnizca birden fazlaysa ekranda
            // secim cikar; tek rafta olan urun soru sormadan oradan duser.
            'shelves' => $urunler->mapWithKeys(fn (Product $urun) => [
                $urun->id => $this->shelvesFor($urun),
            ]),
            'todayEntries' => Consumption::with(['product', 'location'])
                ->where('user_id', $user->id)
                ->where('is_undone', false)
                ->whereBetween('consumed_at', [$gunBasi, $gunSonu])
                ->orderByDesc('consumed_at')
                ->get(),
            'monthTotal' => $user->getCurrentMonthTotal(),
            'monthItems' => $user->getCurrentMonthItemCount(),
        ]);
    }

    public function store(Request $request, PackageCoverage $kapsam)
    {
        $veri = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $urun = Product::findOrFail($veri['product_id']);

        if (! $urun->is_active) {
            return back()->with('error', 'Bu ürün şu an satışta değil.');
        }

        $stok = $this->resolveShelf($urun, $veri['location_id'] ?? null);
        $kapsanan = $kapsam->coveredQuantity(Auth::user(), $urun, $veri['quantity']);

        // Kayit ve stok dusumu birlikte ya da hic: yarim kalan bir dusum
        // kapanis sayiminda sahte fark uretir.
        $kayit = DB::transaction(function () use ($urun, $veri, $stok, $kapsanan) {
            $kayit = Consumption::create([
                'user_id' => Auth::id(),
                // Gercek raf kayda yazilir; bir fark arastirilirken hangi
                // tuketimin hangi rafi dusurdugu gorunur olmali. Urun hicbir
                // rafa bagli degilse sanal lokasyon (Dalga 6c) yedek kalir -
                // sutun NOT NULL ve her ekran location->name okuyor.
                'location_id' => $stok?->location_id ?? Location::selfService()->id,
                'product_id' => $urun->id,
                'quantity' => $veri['quantity'],
                'covered_quantity' => $kapsanan,
                // unit_price o anki fiyat: katalog sonradan degisse de gecmis
                // adisyon degismez.
                'unit_price' => $urun->unit_price,
            ]);

            $stok?->decrement('expected_quantity', $veri['quantity']);

            return $kayit;
        });

        return redirect()->route('user.tab')->with('success', $this->receipt($kayit, $urun));
    }

    /**
     * Geri alma: yalnizca kendi kaydi ve 60 saniye icinde (Consumption::canUndo).
     */
    public function undo(Consumption $consumption)
    {
        abort_unless($consumption->user_id === Auth::id(), 403);

        if (! $consumption->undo()) {
            return back()->with('error', 'Geri alma süresi doldu.');
        }

        // Stok yalnizca geri alma GERCEKLESTIYSE iade edilir; suresi dolmus
        // bir istek rafi sessizce sisirmemeli.
        ProductLocation::where('product_id', $consumption->product_id)
            ->where('location_id', $consumption->location_id)
            ->first()
            ?->increment('expected_quantity', $consumption->quantity);

        return back()->with('success', 'Kayıt geri alındı.');
    }

    /**
     * Urunun bulundugu AKTIF raflar.
     *
     * Kapali raf sayilmaz: stok ekranlarindan cikmis bir rafi dusurmek,
     * kimsenin bakmadigi bir sayiyi bozmak olurdu. Sanal self adisyon
     * lokasyonu da zaten kapali (Dalga 6c), yani kendiliginden eleniyor.
     *
     * @return Collection<int,ProductLocation>
     */
    private function shelvesFor(Product $product): Collection
    {
        return ProductLocation::with('location')
            ->where('product_id', $product->id)
            ->whereHas('location', fn ($q) => $q->where('is_active', true))
            ->orderBy('location_id')
            ->get();
    }

    /**
     * Stogun dusulecegi raf. Hicbir rafta degilse null.
     *
     * Urun iki raftaysa ogrenci SECMELI. Rastgele birini dusurmek iki sahte
     * fark uretirdi: biri eksik, oburu fazla gorunur ve yonetici kapanis
     * sayiminda olmayan bir kaybi arastirirdi. Belirsizligi tahminle
     * kapatmak stok takibini guvenilmez kilar.
     */
    private function resolveShelf(Product $product, ?int $chosen): ?ProductLocation
    {
        $raflar = $this->shelvesFor($product);

        if ($raflar->isEmpty()) {
            return null;
        }

        if ($raflar->count() === 1 && $chosen === null) {
            return $raflar->first();
        }

        $bulunan = $chosen === null
            ? null
            : $raflar->firstWhere('location_id', $chosen);

        if ($bulunan === null) {
            throw ValidationException::withMessages([
                'location_id' => $chosen === null
                    ? 'Bu ürün birden fazla yerde duruyor; nereden aldığını seç.'
                    : 'Seçilen yerde bu ürün yok.',
            ]);
        }

        return $bulunan;
    }

    /**
     * Ekranda gorunen onay metni.
     *
     * Kapsam durumu ACIKCA yaziliyor: limit asiminda engelleme yok
     * (karar, SS7), dolayisiyla ogrencinin ne zaman para odedigini ancak
     * burada gorebilir.
     */
    private function receipt(Consumption $kayit, Product $urun): string
    {
        $bas = "{$kayit->quantity} × {$urun->name} adisyonuna eklendi";

        if ($kayit->isCoveredByPackage()) {
            return "{$bas} — paketine dahil, ücret yok. 60 saniye içinde geri alabilirsin.";
        }

        if ($kayit->covered_quantity > 0) {
            return "{$bas} — {$kayit->covered_quantity} adedi paketinden, kalanı {$kayit->formatted_total}. "
                . '60 saniye içinde geri alabilirsin.';
        }

        return "{$bas} ({$kayit->formatted_total}). 60 saniye içinde geri alabilirsin.";
    }
}
