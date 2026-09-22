<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Panelden degistirilebilen ayarlar (Dalga 10b).
 *
 * Simdilik tek alan (kafe konumu) ama kendi sayfasinda: kafe geneli bir ayari
 * masa listesinin ya da izleme ekraninin icine koymak, ileride kafe saatleri
 * ve esikler de gelince dagitilmis bir ayar yuzeyi birakirdi.
 */
class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings', [
            'cafeLocation' => Setting::cafeLocation(),
        ]);
    }

    /**
     * Kafe koordinatini kaydeder.
     *
     * Deger yoneticinin tarayicisindan geliyor ("konumu buradan al"), yani
     * kafede olculuyor. Yine de araliklar dogrulaniyor: elle duzenlenmis bir
     * koordinat mesafe hesabini sessizce bozardi ve hatanin izi kalmazdi.
     */
    public function saveLocation(Request $request): RedirectResponse
    {
        $dogrulanmis = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        Setting::putCafeLocation(
            (float) $dogrulanmis['latitude'],
            (float) $dogrulanmis['longitude'],
        );

        return back()->with('success', 'Kafe konumu kaydedildi.');
    }
}
