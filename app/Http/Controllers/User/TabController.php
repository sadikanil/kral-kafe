<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Consumption;
use App\Models\Location;
use App\Models\Product;
use App\Models\StudyTable;
use App\Services\PackageCoverage;
use App\Support\MonthParameter;
use App\Support\TabSummary;
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
    /**
     * Adisyonum (DEV-506, 26 Ekim 2026): bu ayin ya da ?ay=YYYY-MM ile
     * gecmis bir ayin adisyonu. Urun secme ayri sayfada (add); masa QR'i
     * da ayni gorunumu masa bilgisiyle acar (TableSessionController::show).
     */
    public function index(Request $request)
    {
        [$yil, $ay] = MonthParameter::resolve($request->query('ay'));

        return view('user.tab', TabSummary::for(Auth::user(), $yil, $ay) + ['table' => null, 'session' => null]);
    }

    /** Urun secme ekrani. ?masa=KOD masadan gelindiyse; donus o masanin adisyonu. */
    public function add(Request $request)
    {
        return view('user.tab-add', [
            'products' => Product::where('is_active', true)
                ->orderBy('category')
                ->orderBy('name')
                ->get(),
            'table' => $this->masa($request->query('masa')),
        ]);
    }

    public function store(Request $request, PackageCoverage $kapsam)
    {
        $veri = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'masa' => ['nullable', 'string', 'max:50'],
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

        // Masadan gelindiyse ayni masanin adisyonuna doner (DEV-506).
        $masa = $this->masa($veri['masa'] ?? null);

        return redirect()
            ->to($masa ? route('table.scan', $masa->qr_code) : route('user.tab'))
            ->with('success', $this->receipt($kayit, $urun));
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

    /** Gecerli masa kodu ya da null (uydurma kod yonlendirmeyi degistirmez). */
    private function masa(mixed $kod): ?StudyTable
    {
        return is_string($kod) && $kod !== '' ? StudyTable::where('qr_code', $kod)->first() : null;
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
