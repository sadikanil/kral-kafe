{{--
    Vercel Web Analytics (1 Ekim 2026). Cerez yok, kisi tanimlamaz.

    npm paketi (@vercel/analytics) yerine Vercel'in "diger cerceveler" etiketi:
    betikler paketleyicisiz (public/js), paket inject() icin derleme ister.
    Betik /_vercel/insights altindan, Vercel'in kendisinden gelir; yalnizca
    canlida yuklenir (yerelde 404 olurdu).

    beforeSend gonderilen adresi temizler: sorgu dizesi (arama kutusundaki
    ogrenci adi), sifre sifirlama jetonu ve masa QR kodu Vercel'e gitmez.
--}}
@production
    <script>
        window.va = window.va || function () { (window.vaq = window.vaq || []).push(arguments); };
        window.va('beforeSend', function (olay) {
            var adres = new URL(olay.url);
            adres.search = '';
            adres.hash = '';
            adres.pathname = adres.pathname
                .replace(/^\/sifre-sifirla\/[^/]+/, '/sifre-sifirla/[jeton]')
                .replace(/^\/masa\/[^/]+/, '/masa/[kod]');
            olay.url = adres.toString();
            return olay;
        });
    </script>
    <script defer src="/_vercel/insights/script.js"></script>
@endproduction
