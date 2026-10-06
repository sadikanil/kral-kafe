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
 * Telefon bildirimi (6 Ekim 2026): sunucunun sifreli itmesi gelince
 * bildirim gosterilir, dokununca ilgili sayfa acilir (acik sekme varsa
 * o one gelir). Icerik bildirimIcerigi()'nde suzulur: baska siteye
 * baglanti acilmaz.
 *
 * Mantik strateji(), eskiSurumler() ve bildirimIcerigi()'nde; tests/js/sw.test.mjs onlari
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

    /** Itmeden gelen JSON -> showNotification(baslik, secenekler). */
    function bildirimIcerigi(veri, koken) {
        veri = veri && typeof veri === 'object' ? veri : {};
        var adres = '/bildirimler';

        try {
            var hedef = new URL(String(veri.url || adres), koken);
            if (hedef.origin === koken) {
                adres = hedef.pathname + hedef.search + hedef.hash;
            }
        } catch (e) { /* bozuk adres: bildirimler sayfasi */ }

        return {
            baslik: String(veri.title || 'Kral Kafe').slice(0, 120),
            secenekler: {
                body: String(veri.body || '').slice(0, 300),
                tag: String(veri.tag || 'kral-kafe'),
                icon: '/img/simge-192.png',
                badge: '/img/simge-192.png',
                lang: 'tr',
                data: { url: adres }
            }
        };
    }

    if (typeof module === 'object' && module && module.exports) {
        module.exports = { strateji: strateji, eskiSurumler: eskiSurumler, bildirimIcerigi: bildirimIcerigi };
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

    self.addEventListener('push', function (olay) {
        var veri = {};
        try { veri = olay.data ? olay.data.json() : {}; } catch (e) { veri = {}; }
        var icerik = bildirimIcerigi(veri, self.location.origin);

        olay.waitUntil(self.registration.showNotification(icerik.baslik, icerik.secenekler));
    });

    self.addEventListener('notificationclick', function (olay) {
        olay.notification.close();
        var adres = new URL((olay.notification.data && olay.notification.data.url) || '/bildirimler', self.location.origin).href;

        olay.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (pencereler) {
            for (var i = 0; i < pencereler.length; i++) {
                if (new URL(pencereler[i].url).origin === self.location.origin && 'focus' in pencereler[i]) {
                    return pencereler[i].focus().then(function (p) { return p.navigate ? p.navigate(adres) : p; });
                }
            }
            return self.clients.openWindow(adres);
        }));
    });
})();
