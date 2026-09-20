<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Consumption;
use App\Models\Location;
use App\Models\Product;
use App\Support\LocalDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Self adisyon: ogrenci QR okutmadan, panelden, sistemde tanimli bir urunu
 * kendi hesabina ekler.
 *
 * QR akisindan farki: lokasyon yok (sanal Location::selfService()), stok
 * dusmez, JSON degil form. Ayni Consumption tablosuna yazar; aylik fatura,
 * gecmis ve raporlar hicbir degisiklik olmadan bunu da sayar.
 *
 * Abonelik kontrolu rota grubundaki 'subscription' middleware'inde.
 */
class TabController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        [$gunBasi, $gunSonu] = LocalDay::bounds(LocalDay::today());

        return view('user.tab', [
            'products' => Product::where('is_active', true)->orderBy('category')->orderBy('name')->get(),
            'todayEntries' => Consumption::with('product')
                ->where('user_id', $user->id)
                ->where('location_id', Location::selfService()->id)
                ->where('is_undone', false)
                ->whereBetween('consumed_at', [$gunBasi, $gunSonu])
                ->orderByDesc('consumed_at')
                ->get(),
            'monthTotal' => $user->getCurrentMonthTotal(),
            'monthItems' => $user->getCurrentMonthItemCount(),
        ]);
    }

    public function store(Request $request)
    {
        $veri = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $urun = Product::findOrFail($veri['product_id']);

        if (! $urun->is_active) {
            return back()->with('error', 'Bu ürün şu an satışta değil.');
        }

        // unit_price o anki fiyat: katalog fiyati sonradan degisse de gecmis
        // adisyon degismez (Consumption zaten boyle calisiyor).
        $kayit = Consumption::create([
            'user_id' => Auth::id(),
            'location_id' => Location::selfService()->id,
            'product_id' => $urun->id,
            'quantity' => $veri['quantity'],
            'unit_price' => $urun->unit_price,
        ]);

        return redirect()->route('user.tab')
            ->with('success', "{$veri['quantity']} × {$urun->name} adisyonuna eklendi ({$kayit->formatted_total}). 60 saniye içinde geri alabilirsin.");
    }

    /**
     * Geri alma: yalnizca kendi kaydi ve 60 saniye icinde (Consumption::canUndo).
     * Self adisyon stok dusmedigi icin stok geri yuklemesi de yok.
     */
    public function undo(Consumption $consumption)
    {
        abort_unless($consumption->user_id === Auth::id(), 403);

        if (! $consumption->undo()) {
            return back()->with('error', 'Geri alma süresi doldu.');
        }

        return back()->with('success', 'Kayıt geri alındı.');
    }
}
