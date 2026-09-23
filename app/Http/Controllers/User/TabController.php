<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Consumption;
use App\Models\Location;
use App\Models\Product;
use App\Services\PackageCoverage;
use App\Support\LocalDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Self adisyon: ogrenci QR okutmadan, panelden, sistemde tanimli bir urunu
 * kendi hesabina ekler.
 *
 * Dalga 8'de QR tuketim akisi KALKTI ve iki isi devraldi:
 *
 *  1. STOK DUSUMU. Stok mutabakati stogun sayimlar ARASINDA
 *     tuketimle dusmesine dayaniyor; stogu dusen tek yer QR akisiydi. Self
 *     adisyon stoga dokunmasaydi kapanis sayimi her gun mesru satisi KAYIP
 *     olarak isaretlerdi.
 *  2. PAKET KAPSAMI. package_items Dalga 7'den beri yalnizca tanimdi.
 *
 * Kullanicinin istegi QR'in TUKETIM icin okutulmamasiydi. Dalga 29'dan beri
 * stok urunun uzerinde: raf secimi yok, dusum Product::adjustStock().
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
        ]);

        $urun = Product::findOrFail($veri['product_id']);

        if (! $urun->is_active) {
            return back()->with('error', 'Bu ürün şu an satışta değil.');
        }

        $kapsanan = $kapsam->coveredQuantity(Auth::user(), $urun, $veri['quantity']);

        // Kayit ve stok dusumu birlikte ya da hic: yarim kalan bir dusum
        // kapanis sayiminda sahte fark uretir.
        $kayit = DB::transaction(function () use ($urun, $veri, $kapsanan) {
            $kayit = Consumption::create([
                'user_id' => Auth::id(),
                // Urunun konumu kayda yazilir (Dalga 29: tek konum etiketi);
                // bir fark arastirilirken hangi tuketimin hangi konumu
                // dusurdugu gorunur olmali. Konum yoksa sanal lokasyon
                // (Dalga 6c) yedek kalir - sutun NOT NULL.
                'location_id' => $urun->location_id ?? Location::selfService()->id,
                'product_id' => $urun->id,
                'quantity' => $veri['quantity'],
                'covered_quantity' => $kapsanan,
                // unit_price o anki fiyat: katalog sonradan degisse de gecmis
                // adisyon degismez.
                'unit_price' => $urun->unit_price,
            ]);

            // Takip kapaliysa (sicak icecek) hicbir sey yapmaz; kritik
            // sayiya inerse yoneticiye bildirim Product'in olayindan.
            $urun->adjustStock(-$veri['quantity']);

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
        $consumption->product?->adjustStock($consumption->quantity);

        return back()->with('success', 'Kayıt geri alındı.');
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
