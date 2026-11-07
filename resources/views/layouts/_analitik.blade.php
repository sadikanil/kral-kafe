{{--
    Vercel Web Analytics (1 Ekim 2026) ve Speed Insights (2 Kasim 2026).
    Cerez yok, kisi tanimlamaz.

    npm paketleri (@vercel/analytics, @vercel/speed-insights) yerine Vercel'in
    "diger cerceveler" etiketleri: betikler paketleyicisiz (public/js), paket
    inject() icin derleme ister. Betikler /_vercel/insights ve
    /_vercel/speed-insights altindan, Vercel'in kendisinden gelir; yalnizca
    canlida yuklenir (yerelde 404 olurdu).

    beforeSend gonderilen adresi temizler (ikisinde de ayni): sorgu dizesi
    (arama kutusundaki ogrenci adi), sifre sifirlama jetonu ve masa QR kodu
    Vercel'e gitmez.
--}}
@production
    <script @nonce>
        (function () {
            function temizle(olay) {
                var adres = new URL(olay.url);
                adres.search = '';
                adres.hash = '';
                adres.pathname = adres.pathname
                    .replace(/^\/sifre-sifirla\/[^/]+/, '/sifre-sifirla/[jeton]')
                    .replace(/^\/masa\/[^/]+/, '/masa/[kod]');
                olay.url = adres.toString();
                return olay;
            }

            window.va = window.va || function () { (window.vaq = window.vaq || []).push(arguments); };
            window.va('beforeSend', temizle);
            window.si = window.si || function () { (window.siq = window.siq || []).push(arguments); };
            window.si('beforeSend', temizle);
        })();
    </script>
    <script @nonce defer src="/_vercel/insights/script.js"></script>
    <script @nonce defer src="/_vercel/speed-insights/script.js"></script>
@endproduction
