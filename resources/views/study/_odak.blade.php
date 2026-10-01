{{--
    Odak modu (Faz 4). Sayacta, yalnizca calisirken (molada yok). Istege
    bagli: acilinca simsiyah ekran ve soluk saat (public/js/odak.js).

    Web uygulamasi baska uygulamalari engelleyemez; bunu isletim sistemi
    yapar. Kart iki yolu da anlatir (iPhone: Rehberli Erisim, Android:
    Uygulama sabitleme). Odaktayken uygulamadan ayrilmak sayilir; ogrenci ve
    koc gorur, veli gormez. Sure calismadan dusulmez.

    Beklenen: $session (acik StudySession).
--}}
@php
    $ayrilis = $session->focus_away_count;
    $ayrilisDakika = max(1, (int) round($session->focus_away_seconds / 60));
@endphp

<div class="card mb-3">
    <div class="card-body">
        <div class="focus-card-row">
            <div class="flex-1">
                <h2 class="focus-card-title">Odak modu</h2>
                <p class="text-muted text-sm mb-0">Ekran kararır, yalnızca saat kalır. Uygulamadan ayrılırsan sayılır; koçun da görür.</p>
                <p class="text-sm mb-0 mt-1">
                    Bu oturum: <strong class="js-odak-sayi" aria-live="polite">{{ $ayrilis > 0 ? $ayrilis . ' kez · ' . $ayrilisDakika . ' dk' : 'Hiç ayrılmadın' }}</strong>
                </p>
            </div>
            <button type="button" class="btn btn-primary js-odak-ac"><x-icon name="moon" /> Başlat</button>
        </div>

        <details class="focus-help">
            <summary>Telefonu tamamen kilitlemek için</summary>
            <div class="focus-help-body">
                <p class="mb-1"><strong>iPhone</strong></p>
                <ol class="mb-2">
                    <li>Ayarlar › Erişilebilirlik › Rehberli Erişim'i aç ve bir parola belirle.</li>
                    <li>Bu sayfadayken yan tuşa üç kez bas, Başlat'a dokun.</li>
                    <li>Çıkmak için yan tuşa yine üç kez bas ve parolayı gir.</li>
                </ol>
                <p class="mb-1"><strong>Android</strong></p>
                <ol class="mb-2">
                    <li>Ayarlar › Güvenlik › Uygulama sabitleme'yi aç (bazı telefonlarda "Diğer güvenlik ayarları" altında).</li>
                    <li>Son uygulamalar ekranında tarayıcının simgesine dokun, Sabitle'yi seç.</li>
                    <li>Çıkmak için Geri ve Son uygulamalar tuşlarını birlikte basılı tut.</li>
                </ol>
                <p class="mb-0">Bildirimleri susturmak için Denetim Merkezi'nden Odaklanma'yı (Android'de Rahatsız Etmeyin) aç.</p>
            </div>
        </details>
    </div>
</div>

{{-- Simsiyah ekran: temadan bagimsiz. Betik onu body'nin sonuna tasir ki
     arkadaki sayfa inert yapilabilsin. --}}
<div class="focus-screen" id="odak" hidden role="dialog" aria-modal="true" aria-label="Odak modu"
     data-adres="{{ route('session.focus.away') }}" data-net="{{ $session->minutesSoFar() * 60 }}">
    <div class="focus-screen-clock js-odak-saat" aria-hidden="true"></div>
    <p class="focus-screen-sub">Net çalışma · odaktasın</p>
    <p class="focus-screen-battery js-odak-pil" hidden>Şarj düşük: ekran kendiliğinden kararabilir.</p>
    <button type="button" class="focus-screen-exit js-odak-kapat">Odaktan çık</button>
</div>

@push('scripts')
    <script src="{{ \App\Support\Asset::url('js/odak.js') }}" defer></script>
@endpush
