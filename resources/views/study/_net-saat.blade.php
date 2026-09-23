{{--
    Net calisma saati (QA a11y A12) - sayac sayfasi ve panel karti AYNI parca.

    Beklenen: $saniye (net calisma, sunucuda hesaplanmis), $akiyor (duraklama
    yoksa true). Sure sunucuda hesaplanir; JS yalnizca gorunumu tazeler.

    role="timer" + aria-live="off": ekran okuyucu her saniye konusmasin, ama
    odaklaninca "Net calisma 1 saat 35 dakika" duysun. Metin ve etiket
    yalnizca DAKIKA degisince yazilir.
--}}
@php
    $dk = intdiv($saniye, 60);
    $etiket = 'Net çalışma ' . (intdiv($dk, 60) ? intdiv($dk, 60) . ' saat ' : '') . ($dk % 60) . ' dakika';
@endphp
<div class="session-timer" data-net-saat="{{ $saniye }}" data-akiyor="{{ $akiyor ? 1 : 0 }}"
     role="timer" aria-live="off" aria-label="{{ $etiket }}">{{ sprintf('%02d:%02d', intdiv($dk, 60), $dk % 60) }}</div>

@once
    @push('scripts')
        <script>
            window.NetSaat = (function () {
                // net-saat:bas
                function ikili(n) {
                    return String(n).padStart(2, '0');
                }

                function saatMetni(saniye) {
                    return ikili(Math.floor(saniye / 3600)) + ':' + ikili(Math.floor(saniye % 3600 / 60));
                }

                function saatEtiketi(saniye) {
                    const dk = Math.floor(saniye / 60);
                    const sa = Math.floor(dk / 60);

                    return 'Net çalışma ' + (sa ? sa + ' saat ' : '') + (dk % 60) + ' dakika';
                }

                // Zamani gelmis hatirlaticilarin SONUNCUSU (yoksa -1). Telefon
                // uykudan donunce gecmis her hatirlatici birer saniye arayla
                // titriyordu; yalnizca en guncel olani anlamli.
                function gosterilecek(hatirlaticilar, siradaki, araliksiz) {
                    let secilen = -1;
                    for (let i = siradaki; i < hatirlaticilar.length && hatirlaticilar[i].at <= araliksiz; i++) {
                        secilen = i;
                    }

                    return secilen;
                }

                // Sayfa uzun sure gizli kaldiysa sunucudan tazelenir: 21:00'de
                // kapanmis ya da yoneticinin bitirdigi oturum "Calisiyorsun"
                // diye akmaya devam ediyordu. Ogrenci bir sey yaziyorsa
                // yazdigi kaybolmasin diye yenilenmez.
                function yenilensinMi(gizliMs, yaziliyor) {
                    return gizliMs > 60000 && !yaziliyor;
                }
                // net-saat:son

                const yuklendi = Date.now();
                const saatler = document.querySelectorAll('[data-net-saat]');

                saatler.forEach(function (el) {
                    if (el.dataset.akiyor !== '1') {
                        return;
                    }

                    const net0 = Number(el.dataset.netSaat);
                    let sonDakika = Math.floor(net0 / 60);

                    setInterval(function () {
                        const net = net0 + Math.floor((Date.now() - yuklendi) / 1000);
                        const dk = Math.floor(net / 60);

                        if (dk !== sonDakika) {
                            sonDakika = dk;
                            el.textContent = saatMetni(net);
                            el.setAttribute('aria-label', saatEtiketi(net));
                        }
                    }, 1000);
                });

                if (saatler.length > 0) {
                    let gizlendi = null;

                    document.addEventListener('visibilitychange', function () {
                        if (document.hidden) {
                            gizlendi = Date.now();
                            return;
                        }

                        if (gizlendi === null) {
                            return;
                        }

                        const yaziliyor = Array.from(document.querySelectorAll('input[type=text], input[type=number], textarea'))
                            .some(function (alan) { return alan.value !== ''; });

                        if (yenilensinMi(Date.now() - gizlendi, yaziliyor)) {
                            window.location.reload();
                        }

                        gizlendi = null;
                    });
                }

                return { gosterilecek: gosterilecek };
            })();
        </script>
    @endpush
@endonce
