/*
 * Telefon bildirimi (6 Ekim 2026): bu cihazi abone yapar ya da cikarir.
 *
 *  - Bildirimler sayfasindaki [data-telefon-bildirim] karti: durum + Ac/Kapat.
 *  - Zildeki [data-telefon-oneri] baglantisi: destekleniyor ve henuz acik
 *    degilse gorunur.
 *  - Cikis: bu cihazin aboneligi birakilir; ortak telefonda bir sonraki
 *    kisiye onceki hesabin bildirimi gitmez.
 *
 * iPhone'da itme yalnizca ana ekrana eklenmis uygulamada (iOS 16.4+) var;
 * Safari sekmesinde kart bunu anlatir.
 */
(function () {
    'use strict';

    var nav = window.navigator;
    var destek = 'serviceWorker' in nav && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
    var ios = /iPhone|iPad|iPod/.test(nav.userAgent) || (nav.platform === 'MacIntel' && nav.maxTouchPoints > 1);
    var anaEkran = window.matchMedia('(display-mode: standalone)').matches || nav.standalone === true;
    var belirtec = (document.querySelector('meta[name="csrf-token"]') || {}).content;

    function b64(veri) {
        var dolgu = '='.repeat((4 - veri.length % 4) % 4);
        var ham = atob((veri + dolgu).replace(/-/g, '+').replace(/_/g, '/'));
        var dizi = new Uint8Array(ham.length);
        for (var i = 0; i < ham.length; i++) { dizi[i] = ham.charCodeAt(i); }
        return dizi;
    }

    function ayni(a, b) {
        if (!a || !b) { return false; }
        a = new Uint8Array(a);
        if (a.length !== b.length) { return false; }
        for (var i = 0; i < a.length; i++) { if (a[i] !== b[i]) { return false; } }
        return true;
    }

    function gonder(adres, yontem, govde) {
        return fetch(adres, {
            method: yontem,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': belirtec },
            body: JSON.stringify(govde)
        }).then(function (y) { if (!y.ok) { throw new Error('sunucu ' + y.status); } return y; });
    }

    /** Saglayiciya ulasilamazsa abonelik sonsuza dek bekleyebiliyor. */
    function sureli(soz, ms) {
        return Promise.race([soz, new Promise(function (_, red) {
            setTimeout(function () { red(new Error('zaman asimi')); }, ms);
        })]);
    }

    function mevcut() {
        return nav.serviceWorker.getRegistration('/').then(function (kayit) {
            return kayit ? kayit.pushManager.getSubscription() : null;
        });
    }

    function abone(anahtar) {
        return nav.serviceWorker.register('/sw.js').then(function () {
            return nav.serviceWorker.ready;
        }).then(function (kayit) {
            return kayit.pushManager.getSubscription().then(function (eski) {
                // Sunucu anahtari degistiyse eski abonelik ise yaramaz.
                if (eski && !ayni(eski.options && eski.options.applicationServerKey, anahtar)) {
                    return eski.unsubscribe().then(function () { return null; });
                }
                return eski;
            }).then(function (eski) {
                return eski || kayit.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: anahtar });
            });
        });
    }

    function kart(kutu) {
        var durum = kutu.querySelector('[data-durum]');
        var ac = kutu.querySelector('[data-ac]');
        var kapat = kutu.querySelector('[data-kapat]');
        var anahtar = b64(kutu.getAttribute('data-anahtar'));
        var adres = kutu.getAttribute('data-adres');

        function goster(metin, acik, kapali) {
            durum.textContent = metin;
            ac.hidden = !acik;
            kapat.hidden = !kapali;
        }

        if (!destek) {
            goster(ios && !anaEkran
                ? 'iPhone\'da bildirim için önce Safari\'de Paylaş → "Ana Ekrana Ekle" deyin, sonra Kral Kafe\'yi ana ekrandaki simgeden açıp bu sayfaya gelin.'
                : 'Bu tarayıcı telefon bildirimini desteklemiyor. Chrome ya da Safari\'nin güncel sürümünü deneyin.', false, false);
            return;
        }

        function yenile() {
            if (Notification.permission === 'denied') {
                goster('Bildirim izni kapalı. Telefonun ayarlarından Kral Kafe için bildirimlere izin verin, sonra sayfayı yenileyin.', false, false);
                return Promise.resolve();
            }
            return mevcut().then(function (abonelik) {
                if (abonelik && Notification.permission === 'granted') {
                    goster('Bu cihazda açık. Yeni bildirimler telefona da gelir.', false, true);
                    // Sunucu kaydi tazelenir (anahtar ya da hesap degismis olabilir).
                    return gonder(adres, 'POST', abonelik.toJSON()).catch(function () {});
                }
                goster('Bu cihazda kapalı. Açarsanız deneme sonucu, devamsızlık gibi bildirimler uygulama kapalıyken de gelir.', true, false);
            });
        }

        ac.addEventListener('click', function () {
            ac.disabled = true;
            Notification.requestPermission().then(function (izin) {
                if (izin !== 'granted') { return yenile(); }
                return sureli(abone(anahtar), 20000).then(function (abonelik) {
                    return gonder(adres, 'POST', abonelik.toJSON());
                }).then(yenile);
            }).catch(function () {
                goster('Açılamadı; biraz sonra yeniden deneyin.', true, false);
            }).then(function () { ac.disabled = false; });
        });

        kapat.addEventListener('click', function () {
            kapat.disabled = true;
            mevcut().then(function (abonelik) {
                if (!abonelik) { return null; }
                var uc = abonelik.endpoint;
                return abonelik.unsubscribe().then(function () {
                    return gonder(adres, 'DELETE', { endpoint: uc }).catch(function () {});
                });
            }).then(yenile).then(function () { kapat.disabled = false; });
        });

        yenile();
    }

    var kutu = document.querySelector('[data-telefon-bildirim]');
    if (kutu) { kart(kutu); }

    var oneri = document.querySelector('[data-telefon-oneri]');
    if (oneri && destek && Notification.permission !== 'denied') {
        mevcut().then(function (abonelik) { oneri.hidden = !!abonelik; }).catch(function () {});
    }

    // Cikis: once bu cihazin aboneligi birakilir (en fazla 1,5 sn beklenir).
    if (destek) {
        document.querySelectorAll('form[data-cikis]').forEach(function (form) {
            form.addEventListener('submit', function (olay) {
                if (form.getAttribute('data-birakildi')) { return; }
                olay.preventDefault();
                form.setAttribute('data-birakildi', '1');
                var gitti = false;
                function devam() { if (!gitti) { gitti = true; form.submit(); } }
                mevcut().then(function (abonelik) { return abonelik && abonelik.unsubscribe(); })
                    .catch(function () {}).then(devam);
                setTimeout(devam, 1500);
            });
        });
    }
})();
