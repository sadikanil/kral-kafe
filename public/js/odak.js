/*
 * Odak modu (Faz 4): calisma sayacinda istege bagli, simsiyah bir ekran.
 *
 * Web uygulamasi baska uygulamalari engelleyemez (bunu yalnizca isletim
 * sistemi yapar: iPhone'da Rehberli Erisim, Android'de Uygulama sabitleme;
 * sayfa ikisini de anlatir). Odak modunun yaptigi:
 *
 *  - Ekrani acik tutar (Wake Lock), ama YALNIZCA odak modunda ve pil
 *    yeterliyken: sarjda degilse %20'nin altinda kilit birakilir, ekran
 *    telefonun kendi ayariyla kararir. Pil bilgisi yalniz Android'de var;
 *    iPhone'da bilinmeyen pil "uygun" sayilir.
 *  - Simsiyah zemin ve soluk, dakikada bir degisen saat: OLED ekranda
 *    piksellerin cogu kapali, saniyede bir cizim yok.
 *  - Odaktayken uygulamadan 3 sn ve uzeri ayrilis sunucuya bildirilir;
 *    ogrenci ve koc gorur. Sure calismadan dusulmez.
 *
 * Mantik odakModu()'nda; tests/js/odak.test.mjs sahte ortamla cagirir.
 * Tarayicida yalnizca baslat() calisir ve sayfada #odak yoksa hicbir sey yapmaz.
 */
(function () {
    'use strict';

    /** Bildirime bir an bakmak, ekranin donmesi: ayrilis sayilmaz. */
    var EN_KISA_SANIYE = 3;
    var PIL_SINIRI = 0.2;

    function pilUygun(pil) {
        return !pil || pil.charging || pil.level >= PIL_SINIRI;
    }

    function iki(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function saatMetni(saniye) {
        return iki(Math.floor(saniye / 3600)) + ':' + iki(Math.floor(saniye % 3600 / 60));
    }

    /**
     * @param {{simdi, ekranKilidi, pil, gonder, tamEkran, gorunum}} o
     *   ekranKilidi: navigator.wakeLock ya da null; pil: getBattery ya da null;
     *   tamEkran: {iste, birak} ya da null; gorunum: {ac, kapat, pilNotu}.
     */
    function odakModu(o) {
        var acik = false;
        var kilit = null;
        var pil = null;
        var gizlenme = null;

        function kilitAl() {
            if (!acik || kilit || !o.ekranKilidi || !pilUygun(pil)) {
                return Promise.resolve();
            }

            return o.ekranKilidi.request('screen')
                .then(function (k) { kilit = k; })
                .catch(function () {});
        }

        function kilitBirak() {
            var k = kilit;
            kilit = null;

            return k ? k.release().catch(function () {}) : Promise.resolve();
        }

        function pilDegisti() {
            if (!acik) {
                return;
            }
            o.gorunum.pilNotu(!pilUygun(pil));
            if (pilUygun(pil)) {
                kilitAl();
            } else {
                kilitBirak();
            }
        }

        function pilOgren() {
            if (!o.pil || pil) {
                return Promise.resolve();
            }

            return o.pil().then(function (p) {
                pil = p;
                p.addEventListener('levelchange', pilDegisti);
                p.addEventListener('chargingchange', pilDegisti);
            }).catch(function () {});
        }

        return {
            acikMi: function () {
                return acik;
            },

            ac: function () {
                if (acik) {
                    return Promise.resolve();
                }
                acik = true;
                o.gorunum.ac();
                var tam = o.tamEkran ? o.tamEkran.iste().catch(function () {}) : Promise.resolve();

                return pilOgren().then(function () {
                    o.gorunum.pilNotu(!pilUygun(pil));
                    return kilitAl();
                }).then(function () {
                    return tam;
                });
            },

            kapat: function () {
                if (!acik) {
                    return Promise.resolve();
                }
                acik = false;
                gizlenme = null;
                o.gorunum.kapat();
                var tam = o.tamEkran ? o.tamEkran.birak().catch(function () {}) : Promise.resolve();

                return Promise.all([kilitBirak(), tam]);
            },

            /** Tarayici gizlenen sayfanin ekran kilidini kendisi birakir. */
            gizlendi: function () {
                if (acik) {
                    gizlenme = o.simdi();
                    kilit = null;
                }
            },

            gorundu: function () {
                if (!acik || gizlenme === null) {
                    return Promise.resolve();
                }
                var saniye = Math.round((o.simdi() - gizlenme) / 1000);
                gizlenme = null;
                if (saniye >= EN_KISA_SANIYE) {
                    o.gonder(saniye);
                }

                return kilitAl();
            },
        };
    }

    // --- Tarayici baglantisi -------------------------------------------------

    function baslat(pencere, belge) {
        var ekran = belge.getElementById('odak');
        var acDugmesi = belge.querySelector('.js-odak-ac');
        if (!ekran || !acDugmesi) {
            return;
        }

        // Arkadaki sayfa inert yapilabilsin diye ekran body'nin sonunda.
        belge.body.appendChild(ekran);
        var arkasi = [belge.getElementById('icerik'), belge.getElementById('sidebar'), belge.querySelector('.bottom-nav')]
            .filter(function (el) { return el; });

        var saat = ekran.querySelector('.js-odak-saat');
        var pilNotu = ekran.querySelector('.js-odak-pil');
        var cikis = ekran.querySelector('.js-odak-kapat');
        var jeton = belge.querySelector('meta[name="csrf-token"]');
        var net0 = Number(ekran.getAttribute('data-net')) || 0;
        var yuklendi = Date.now();
        var zamanlayici = null;

        function netSaniye() {
            return net0 + Math.floor((Date.now() - yuklendi) / 1000);
        }

        // Saat dakika basinda degisir; aradaki saniyelerde hic cizim yok.
        function saatiKur() {
            saat.textContent = saatMetni(netSaniye());
            zamanlayici = pencere.setTimeout(saatiKur, 60000 - (netSaniye() % 60) * 1000);
        }

        function sayilariYaz(v) {
            belge.querySelectorAll('.js-odak-sayi').forEach(function (el) {
                el.textContent = v.sayi > 0
                    ? v.sayi + ' kez · ' + Math.max(1, Math.round(v.saniye / 60)) + ' dk'
                    : 'Hiç ayrılmadın';
            });
        }

        var kok = belge.documentElement;
        var m = odakModu({
            simdi: function () { return Date.now(); },
            ekranKilidi: pencere.navigator.wakeLock || null,
            pil: pencere.navigator.getBattery ? function () { return pencere.navigator.getBattery(); } : null,
            gonder: function (saniye) {
                pencere.fetch(ekran.getAttribute('data-adres'), {
                    method: 'POST',
                    keepalive: true,
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': jeton ? jeton.getAttribute('content') : '',
                    },
                    body: JSON.stringify({ saniye: saniye }),
                }).then(function (y) { return y.ok ? y.json() : null; })
                    .then(function (v) { if (v) { sayilariYaz(v); } })
                    .catch(function () {});
            },
            tamEkran: kok.requestFullscreen ? {
                iste: function () { return kok.requestFullscreen({ navigationUI: 'hide' }); },
                birak: function () { return belge.fullscreenElement ? belge.exitFullscreen() : Promise.resolve(); },
            } : null,
            gorunum: {
                ac: function () {
                    ekran.hidden = false;
                    belge.body.classList.add('is-focusing');
                    arkasi.forEach(function (el) { el.inert = true; });
                    saatiKur();
                    cikis.focus();
                },
                kapat: function () {
                    ekran.hidden = true;
                    belge.body.classList.remove('is-focusing');
                    arkasi.forEach(function (el) { el.inert = false; });
                    pencere.clearTimeout(zamanlayici);
                    acDugmesi.focus();
                },
                pilNotu: function (dusuk) {
                    pilNotu.hidden = !dusuk;
                },
            },
        });

        acDugmesi.addEventListener('click', function () { m.ac(); });
        cikis.addEventListener('click', function () { m.kapat(); });
        belge.addEventListener('keydown', function (olay) {
            if (olay.key === 'Escape' && m.acikMi()) {
                m.kapat();
            }
        });
        belge.addEventListener('visibilitychange', function () {
            if (belge.visibilityState === 'hidden') {
                m.gizlendi();
            } else {
                m.gorundu();
            }
        });
    }

    var disari = { pilUygun: pilUygun, saatMetni: saatMetni, odakModu: odakModu };

    if (typeof module === 'object' && module && module.exports) {
        module.exports = disari;
    } else {
        baslat(window, document);
    }
})();
