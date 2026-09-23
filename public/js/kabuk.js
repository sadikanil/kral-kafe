/*
 * Kabuk betigi (Faz 2 / H): her sayfada calisir, bagimliligi yok.
 *
 *  1. Cift gonderim kilidi: yavas baglantida ikinci dokunus ikinci adisyon
 *     satiri, ikinci odeme demekti. Gonderilen formun dugmesi kapanir.
 *  2. Telefon menusu: durum (aria-expanded), odak, Escape, kaydirma kilidi.
 *  3. Zil paneli: disari dokununca ve Escape ile kapanir.
 *  4. Hata ozeti: acilista odak alir; satirlar hatali alana goturur ve
 *     alanlar aria-invalid / aria-describedby ile hatalarina baglanir.
 *  5. Onay (Faz 3): data-confirm tasiyan form confirm() yerine alttan
 *     acilan eylem sayfasiyla sorulur; dialog yoksa tarayicinin onayi.
 *  6. Bildirim balonu (Faz 3): kapat dugmesi, acilista sesli duyuru, ust
 *     cubukta kaydirinca kucuk baslik.
 *
 * Mantik asagidaki fonksiyonlarda; tests/js/kabuk.test.mjs onlari sahte
 * elemanlarla cagirir. Tarayicida yalnizca baslat() calisir (module yok).
 */
(function () {
    'use strict';

    var BEKLEME_METNI = 'İşleniyor…';

    // --- 1. Cift gonderim kilidi ------------------------------------------

    /**
     * Yalnizca veri yazan formlar kilitlenir: GET filtre formunu iki kez
     * gondermek zararsiz; yeni sekmeye giden form ise sayfada kalir ve
     * kilitlenirse bir daha basilamaz.
     */
    function kilitlenirMi(form, gonderen) {
        var yontem = (form.getAttribute('method') || 'get').toLowerCase();
        var hedef = (gonderen && gonderen.getAttribute('formtarget')) || form.getAttribute('target') || '';

        return yontem === 'post' && (hedef === '' || hedef === '_self');
    }

    function dugmeyiBeklet(dugme) {
        var girdi = dugme.tagName === 'INPUT';
        var eski = girdi ? dugme.value : dugme.innerHTML;
        var metin = girdi ? dugme.value : dugme.textContent;

        dugme.disabled = true;
        dugme.setAttribute('aria-busy', 'true');

        // Yalniz simgeli dugmede (cikis, sil) metin degismez: dar dugme
        // "İşleniyor…" ile tasar ve satiri kaydirirdi. Uzun islerde (yapay
        // zeka analizi) gorunum data-bekleme-metni ile ozellestirilebilir.
        if (/\p{L}/u.test(metin)) {
            var yeni = dugme.getAttribute('data-bekleme-metni') || BEKLEME_METNI;
            if (girdi) {
                dugme.value = yeni;
            } else {
                dugme.textContent = yeni;
            }
        }

        return eski;
    }

    /** @param {function(function)} ertele gonderim verisi toplandiktan sonra calistirir */
    function gonderimKilidi(ertele) {
        var kilitli = [];

        return {
            yakala: function (olay) {
                // Satir ici onsubmit="return confirm(...)" iptal edildiyse
                // olay buraya defaultPrevented gelir: form gitmiyor.
                if (olay.defaultPrevented) {
                    return;
                }

                var form = olay.target;
                var gonderen = olay.submitter || null;

                if (!kilitlenirMi(form, gonderen)) {
                    return;
                }

                // Ikinci dokunus: istek zaten yolda.
                if (form.hasAttribute('data-gonderiliyor')) {
                    olay.preventDefault();
                    return;
                }

                form.setAttribute('data-gonderiliyor', '');
                var kayit = { form: form, dugme: gonderen || form.querySelector('[type="submit"], button:not([type])'), eski: null };
                kilitli.push(kayit);

                if (kayit.dugme) {
                    // Olay sirasinda kapatilan dugmenin name=value'su
                    // gonderimden duser; kapatma veri toplandiktan sonra.
                    ertele(function () {
                        kayit.eski = dugmeyiBeklet(kayit.dugme);
                    });
                }
            },

            /**
             * Geri/ileri onbellegi (bfcache) sayfayi donmus haliyle getirir;
             * cozulmezse dugmeler kalici olarak kapali kalir.
             */
            coz: function () {
                kilitli.forEach(function (kayit) {
                    kayit.form.removeAttribute('data-gonderiliyor');

                    if (kayit.dugme && kayit.eski !== null) {
                        kayit.dugme.disabled = false;
                        kayit.dugme.removeAttribute('aria-busy');
                        if (kayit.dugme.tagName === 'INPUT') {
                            kayit.dugme.value = kayit.eski;
                        } else {
                            kayit.dugme.innerHTML = kayit.eski;
                        }
                    }
                });
                kilitli = [];
            },
        };
    }

    // --- 2. Telefon menusu -------------------------------------------------

    /** @param {{kenar, karartma, ana, govde, dugme}} p */
    function menu(p) {
        function acikMi() {
            return p.kenar.classList.contains('open');
        }

        function ac() {
            p.kenar.classList.add('open');
            p.karartma.classList.add('open');
            p.dugme.setAttribute('aria-expanded', 'true');
            // Arkadaki sayfa ne kayar ne de Tab ile gezilir.
            p.govde.style.overflow = 'hidden';
            p.ana.inert = true;

            var ilk = p.kenar.querySelector('a[href], button');
            if (ilk) {
                ilk.focus();
            }
        }

        function kapat(odagiGeriVer) {
            p.kenar.classList.remove('open');
            p.karartma.classList.remove('open');
            p.dugme.setAttribute('aria-expanded', 'false');
            p.govde.style.overflow = '';
            p.ana.inert = false;

            if (odagiGeriVer) {
                p.dugme.focus();
            }
        }

        return {
            acikMi: acikMi,
            ac: ac,
            kapat: kapat,
            degistir: function () {
                if (acikMi()) {
                    kapat(true);
                } else {
                    ac();
                }
            },
        };
    }

    // --- 3. Zil paneli -----------------------------------------------------

    /** <details> paneli yalnizca zile tekrar basinca kapaniyordu. */
    function zilDisariTiklandi(zil, hedef) {
        if (zil.hasAttribute('open') && !zil.contains(hedef)) {
            zil.removeAttribute('open');
        }
    }

    // --- 4. Hata ozeti -----------------------------------------------------

    /**
     * Laravel hata anahtari noktali ("subjects.7.correct"), formdaki ad
     * koseli ("subjects[7][correct]"); ikisi de ve dizi bicimi denenir.
     */
    function alanAdlari(anahtar) {
        var parca = anahtar.split('.');
        var koseli = parca[0] + parca.slice(1).map(function (p) { return '[' + p + ']'; }).join('');
        var adaylar = [koseli, anahtar, anahtar + '[]'];

        return adaylar.filter(function (ad, i) { return adaylar.indexOf(ad) === i; });
    }

    function alaniBul(belge, anahtar) {
        var adlar = alanAdlari(anahtar);

        for (var i = 0; i < adlar.length; i++) {
            var bulunan = belge.getElementsByName(adlar[i]);
            for (var j = 0; j < bulunan.length; j++) {
                if (bulunan[j].type !== 'hidden') {
                    return bulunan[j];
                }
            }
        }

        return null;
    }

    function tanimla(eleman, onek, sira) {
        if (!eleman.id) {
            eleman.id = onek + sira;
        }

        return eleman.id;
    }

    /** Ozet satirini, alan sayfada varsa ona goturen baglantiya cevirir. */
    function ozetiBagla(belge, satirlar) {
        satirlar.forEach(function (satir, sira) {
            var alan = alaniBul(belge, satir.getAttribute('data-hata-alani'));
            if (!alan) {
                return;
            }

            alan.setAttribute('aria-invalid', 'true');

            var baglanti = belge.createElement('a');
            baglanti.href = '#' + tanimla(alan, 'kabuk-alan-', sira);
            baglanti.textContent = satir.textContent;
            baglanti.addEventListener('click', function (olay) {
                olay.preventDefault();
                alan.focus();
            });

            satir.textContent = '';
            satir.appendChild(baglanti);
        });
    }

    /**
     * Kirmizi cerceve yalniz renk; ekran okuyucu icin alan hatali isaretlenir
     * ve hemen ardindaki .invalid-feedback aciklama olarak baglanir.
     */
    function alanlariIsaretle(alanlar) {
        alanlar.forEach(function (alan, sira) {
            alan.setAttribute('aria-invalid', 'true');

            var kardes = alan.nextElementSibling;
            while (kardes && !kardes.classList.contains('invalid-feedback')) {
                kardes = kardes.nextElementSibling;
            }
            if (!kardes) {
                return;
            }

            var kimlik = tanimla(kardes, 'kabuk-hata-', sira);
            var onceki = alan.getAttribute('aria-describedby');
            if (!onceki || onceki.split(' ').indexOf(kimlik) === -1) {
                alan.setAttribute('aria-describedby', onceki ? onceki + ' ' + kimlik : kimlik);
            }
        });
    }

    // --- 5. Onay sayfasi --------------------------------------------------------

    /**
     * Satir ici onsubmit="return confirm(...)" yerine data-confirm="..." (ve
     * istege bagli data-confirm-ok="Sil"). Gonderen dugmenin kendi sorusu
     * formunkinden once gelir. Sayfa beklerken form durur; "evet" gelince AYNI
     * dugmeyle yeniden gonderilir (name=value kaybolmasin) ve o gonderim
     * sorulmadan gecer.
     *
     * Belgede yakalama asamasinda calisir: cift gonderim kilidi (penceredeki
     * kabarcik) durdurulan ilk gonderimi defaultPrevented gorup atlar.
     *
     * @param {function(string, string, function(boolean))} sor
     */
    function onay(sor) {
        return {
            yakala: function (olay) {
                var form = olay.target;
                var gonderen = olay.submitter || null;
                var nitelik = function (ad) {
                    return (gonderen && gonderen.getAttribute(ad)) || form.getAttribute(ad);
                };
                var metin = nitelik('data-confirm');

                if (!metin) {
                    return;
                }
                if (form.hasAttribute('data-onaylandi')) {
                    form.removeAttribute('data-onaylandi');
                    return;
                }

                olay.preventDefault();
                sor(metin, nitelik('data-confirm-ok') || 'Onayla', function (evet) {
                    if (!evet) {
                        return;
                    }
                    form.setAttribute('data-onaylandi', '');
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(gonderen || undefined);
                    } else {
                        form.submit();
                    }
                });
            },
        };
    }

    /**
     * Soruyu soran: layouts/app'teki <dialog id="onay">. Eski tarayicida
     * (showModal yok) ya da dialogsuz sayfada tarayicinin kendi onayi.
     * Escape ve Vazgec kapanis degerini bos birakir: "hayir".
     */
    function sorucu(pencere, pencereOgesi) {
        var d = pencereOgesi;

        if (!d || typeof d.showModal !== 'function') {
            return function (metin, etiket, sonuc) {
                sonuc(pencere.confirm(metin));
            };
        }

        var bekleyen = null;
        d.addEventListener('close', function () {
            var sonuc = bekleyen;
            bekleyen = null;
            if (sonuc) {
                sonuc(d.returnValue === 'evet');
            }
        });

        return function (metin, etiket, sonuc) {
            d.querySelector('.js-onay-metni').textContent = metin;
            d.querySelector('.js-onay-evet').textContent = etiket;
            d.returnValue = '';
            bekleyen = sonuc;
            d.showModal();
        };
    }

    // --- 6. Bildirim balonu ------------------------------------------------------

    /** Cikis hareketi (.is-leaving, app.css) bittikten sonra sayfadan kalkar. */
    function balonuKapat(balon, ertele) {
        balon.classList.add('is-leaving');
        ertele(function () {
            balon.remove();
        });
    }

    /**
     * Sayfa acilirken zaten orada olan canli bolgeyi ekran okuyucular
     * okumuyor; yonlendirmeden sonra gelen "Kaydedildi" hic duyulmazdi. Metin
     * bir an bosaltilip yeniden yazilir: degisiklik olarak duyurulur.
     */
    function duyur(mesaj, ertele) {
        var metin = mesaj.textContent;
        mesaj.textContent = '';
        ertele(function () {
            mesaj.textContent = metin;
        });
    }

    // --- Tarayici baglantisi -------------------------------------------------

    function baslat(pencere, belge) {
        var o = onay(sorucu(pencere, belge.getElementById('onay')));
        belge.addEventListener('submit', o.yakala, true);

        var kilit = gonderimKilidi(function (is) { pencere.setTimeout(is, 0); });
        // Pencerede, kabarcik asamasinda: sayfanin kendi dinleyicileri once calisir.
        pencere.addEventListener('submit', kilit.yakala);
        pencere.addEventListener('pageshow', function (olay) {
            if (olay.persisted) {
                kilit.coz();
            }
        });

        var kenar = belge.getElementById('sidebar');
        var dugme = belge.querySelector('.js-menu-dugmesi');
        var m = null;

        if (kenar && dugme) {
            m = menu({
                kenar: kenar,
                karartma: belge.getElementById('sidebarOverlay'),
                ana: belge.getElementById('icerik'),
                govde: belge.body,
                dugme: dugme,
            });
            dugme.addEventListener('click', m.degistir);
            belge.querySelectorAll('.js-menu-kapat').forEach(function (d) {
                d.addEventListener('click', function () { m.kapat(true); });
            });

            // Masaustune genisleyince menu kavrami yok; kilitler kalmasin.
            var masaustu = pencere.matchMedia('(min-width: 1025px)');
            if (masaustu.addEventListener) {
                masaustu.addEventListener('change', function (olay) {
                    if (olay.matches && m.acikMi()) {
                        m.kapat(false);
                    }
                });
            }
        }

        var zil = belge.querySelector('.notif-bell');
        if (zil) {
            belge.addEventListener('click', function (olay) { zilDisariTiklandi(zil, olay.target); });
        }

        belge.addEventListener('keydown', function (olay) {
            if (olay.key !== 'Escape') {
                return;
            }
            if (m && m.acikMi()) {
                m.kapat(true);
            } else if (zil && zil.hasAttribute('open')) {
                zil.removeAttribute('open');
                zil.querySelector('summary').focus();
            }
        });

        belge.querySelectorAll('.toast').forEach(function (balon) {
            var kapat = balon.querySelector('.js-balon-kapat');
            if (kapat) {
                kapat.addEventListener('click', function () {
                    balonuKapat(balon, function (is) { pencere.setTimeout(is, 250); });
                });
            }
            var mesaj = balon.querySelector('.toast-message');
            if (mesaj && balon.getAttribute('role') === 'status') {
                duyur(mesaj, function (is) { pencere.setTimeout(is, 150); });
            }
        });

        // Buyuk baslik gorunmez olunca ust cubukta kucugu belirir (iOS).
        var cubuk = belge.querySelector('.topbar');
        var baslik = belge.querySelector('.page-title');
        if (cubuk && baslik && 'IntersectionObserver' in pencere) {
            new pencere.IntersectionObserver(function (girdiler) {
                cubuk.classList.toggle('is-scrolled', !girdiler[0].isIntersecting);
            }, { rootMargin: '-' + cubuk.offsetHeight + 'px 0px 0px 0px' }).observe(baslik);
        }

        ozetiBagla(belge, Array.prototype.slice.call(belge.querySelectorAll('[data-hata-alani]')));
        alanlariIsaretle(Array.prototype.slice.call(belge.querySelectorAll('.is-invalid')));

        var odak = belge.querySelector('[data-odakla]');
        if (odak) {
            odak.focus();
        }
    }

    var disari = {
        gonderimKilidi: gonderimKilidi,
        menu: menu,
        zilDisariTiklandi: zilDisariTiklandi,
        alanAdlari: alanAdlari,
        ozetiBagla: ozetiBagla,
        alanlariIsaretle: alanlariIsaretle,
        onay: onay,
        sorucu: sorucu,
        balonuKapat: balonuKapat,
        duyur: duyur,
    };

    if (typeof module === 'object' && module && module.exports) {
        module.exports = disari;
    } else {
        baslat(window, document);
    }
})();
