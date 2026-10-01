/*
 * Service worker (Faz 4): ana ekran uygulamasi ve kopan Wi-Fi.
 *
 * Bilerek dar:
 *  - Sayfalar HER ZAMAN agdan gelir; ag yoksa kisa bir "baglanti yok"
 *    sayfasi (cevrimdisi.html). Oturumlu HTML hic onbellege girmez: ayni
 *    telefonda baska ogrencinin paneli ya da eski bir bakiye gorunmesin.
 *  - Yalnizca surumlu (?v=) stil, betik ve simge once onbellekten: adres
 *    icerikle degistigi icin bayat kalamaz. Yeni surum gelince eskisi silinir.
 *  - Form gonderimi, baska site ve surumsuz dosya: tarayici ne yaparsa o.
 *
 * Mantik strateji() ve eskiSurumler()'de; tests/js/sw.test.mjs onlari
 * dogrudan cagirir. Tarayicida yalnizca olay dinleyicileri kurulur.
 */
(function () {
    'use strict';

    var ONBELLEK = 'kral-kafe-1';
    var YEDEK = '/cevrimdisi.html';

    function strateji(istek, koken) {
        if (istek.method !== 'GET') {
            return null;
        }

        var adres = new URL(istek.url);
        if (adres.origin !== koken) {
            return null;
        }
        if (istek.mode === 'navigate') {
            return 'agdan-yoksa-yedek';
        }
        if (/^\/(css|js|img)\//.test(adres.pathname) && adres.searchParams.has('v')) {
            return 'onbellek-once';
        }

        return null;
    }

    /** Ayni yolun baska surumleri: yeni surum onbellege girince silinir. */
    function eskiSurumler(onbellektekiler, yeni) {
        var yol = new URL(yeni).pathname;

        return onbellektekiler.filter(function (adres) {
            return adres !== yeni && new URL(adres).pathname === yol;
        });
    }

    if (typeof module === 'object' && module && module.exports) {
        module.exports = { strateji: strateji, eskiSurumler: eskiSurumler };
        return;
    }

    self.addEventListener('install', function (olay) {
        olay.waitUntil(caches.open(ONBELLEK).then(function (o) { return o.add(YEDEK); }));
        self.skipWaiting();
    });

    self.addEventListener('activate', function (olay) {
        olay.waitUntil(caches.keys().then(function (adlar) {
            return Promise.all(adlar.filter(function (ad) { return ad !== ONBELLEK; }).map(function (ad) { return caches.delete(ad); }));
        }).then(function () { return self.clients.claim(); }));
    });

    self.addEventListener('fetch', function (olay) {
        var istek = olay.request;
        var yol = strateji(istek, self.location.origin);

        if (yol === 'agdan-yoksa-yedek') {
            olay.respondWith(fetch(istek).catch(function () { return caches.match(YEDEK); }));
        } else if (yol === 'onbellek-once') {
            olay.respondWith(caches.open(ONBELLEK).then(function (o) {
                return o.match(istek).then(function (bulunan) {
                    return bulunan || fetch(istek).then(function (yanit) {
                        if (yanit.ok) {
                            o.put(istek, yanit.clone());
                            o.keys().then(function (anahtarlar) {
                                eskiSurumler(anahtarlar.map(function (a) { return a.url; }), istek.url)
                                    .forEach(function (eski) { o.delete(eski); });
                            });
                        }
                        return yanit;
                    });
                });
            }));
        }
    });
})();
