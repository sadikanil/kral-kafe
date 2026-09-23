@extends('layouts.app')

@section('title', 'QR Okut - Kral Kafe')
@section('page-title', 'QR Okut')

@push('styles')
    <style>
        .scanner-frame {
            position: relative;
            width: 100%;
            max-width: 420px;
            aspect-ratio: 1 / 1;
            margin: 0 auto;
            border-radius: var(--r);
            overflow: hidden;
            background: #000;
        }

        .scanner-frame video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
    </style>
@endpush

@section('content')
    {{--
        Kamera YALNIZCA https'te (ve localhost'ta) acilir; yerelde
        127.0.0.1 ile test edilemez. Bu yuzden her durumda gorunur bir
        yedek yol var: masadaki kodu elle yazma.
    --}}
    <div class="scanner-frame mb-3">
        <video id="js-scanner-video" playsinline muted></video>
    </div>

    {{-- Canli bolge BASTAN sayfada ve gizli degil: gizli bir bolge ayni anda
         acilip doldurulunca ekran okuyucu mesaji okumayabiliyor. --}}
    <div id="js-scanner-durum" role="status" aria-live="polite"></div>

    <div class="session-card mt-3">
        <label for="js-masa-kodu"><strong>Masadaki kodu yaz</strong></label>
        <p class="text-muted" id="js-masa-kodu-ipucu">Kamera açılmazsa, masadaki etikette QR'ın altında yazan kodu buraya yazabilirsin.</p>

        <form method="POST" action="{{ route('table.find') }}" class="d-flex align-items-center gap-2">
            @csrf
            <input type="text" name="code" id="js-masa-kodu" class="form-control" placeholder="MASA-XXXXXXXX"
                   value="{{ old('code') }}" maxlength="50" autocapitalize="characters" autocomplete="off"
                   autocorrect="off" spellcheck="false" enterkeyhint="go"
                   aria-describedby="js-masa-kodu-ipucu" required>
            <button type="submit" class="btn btn-primary">Aç</button>
        </form>

        @error('code')
            <p class="text-danger mt-2">{{ $message }}</p>
        @enderror
    </div>
@endsection

@push('scripts')
    <script>
        // Okuyucu bilerek "en iyi ihtimal" olarak yaziliyor: calisirsa bir
        // dokunus kazandirir, calismazsa asagidaki elle giris formu zaten
        // duruyor. Bu yuzden hicbir hata ogrenciyi ekranda kilitlemiyor.
        //
        // Iki cozucu var (QA a11y A4): BarcodeDetector (Android Chrome) ve
        // YOKSA jsQR. iPhone Safari'de BarcodeDetector yok; yedek olmadan her
        // iPhone'lu ogrenci kodu elle yaziyordu. jsQR yalnizca gerektiginde,
        // sabit surum ve butunluk ozetiyle yuklenir.
        (function () {
            const JSQR_ADRESI = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';
            const JSQR_OZETI = 'sha384-b5Ya4Bq3qCyz39m2ISh+4DxjAIljdeFwK/BsXLuj9gugaNwAcj/ia15fxNZL9Nlx';

            const video = document.getElementById('js-scanner-video');
            const durum = document.getElementById('js-scanner-durum');

            function bildir(mesaj) {
                const kutu = document.createElement('div');
                kutu.className = 'alert alert-info mt-3';
                kutu.textContent = mesaj;
                durum.replaceChildren(kutu);
            }

            // masaAdresi:bas
            // Okunan deger YALNIZCA ayni kokenli /masa/{kod} adresiyse ona
            // gidilir; digerleri null. Eskiden okunan her adrese gidiliyordu:
            // masadaki QR'in ustune yapistirilan bir etiket ogrenciyi sahte
            // bir giris sayfasina gonderebilirdi.
            function masaAdresi(ham, koken) {
                let adres;
                try {
                    adres = new URL(ham, koken);
                } catch (e) {
                    return null;
                }

                if (adres.origin !== koken || !/^\/masa\/[^\/]+\/?$/.test(adres.pathname)) {
                    return null;
                }

                return adres.href;
            }
            // masaAdresi:son

            function jsqrYukle() {
                return new Promise(function (coz, reddet) {
                    const betik = document.createElement('script');
                    betik.src = JSQR_ADRESI;
                    betik.integrity = JSQR_OZETI;
                    betik.crossOrigin = 'anonymous';
                    betik.onload = function () { window.jsQR ? coz(window.jsQR) : reddet(); };
                    betik.onerror = reddet;
                    document.head.appendChild(betik);
                });
            }

            // Cozucu: video -> Promise<string|null>. Iki yol ayni imzayla.
            function cozucuHazirla() {
                if ('BarcodeDetector' in window) {
                    try {
                        const detector = new BarcodeDetector({ formats: ['qr_code'] });
                        return Promise.resolve(function (kaynak) {
                            return detector.detect(kaynak).then(function (kodlar) {
                                return kodlar.length ? kodlar[0].rawValue : null;
                            });
                        });
                    } catch (e) {
                        // Yapici var ama bu platformda QR desteklenmiyor: jsQR'a dus.
                    }
                }

                return jsqrYukle().then(function (jsQR) {
                    const tuval = document.createElement('canvas');
                    const cizim = tuval.getContext('2d', { willReadFrequently: true });

                    return function (kaynak) {
                        if (kaynak.readyState < 2 || !kaynak.videoWidth) {
                            return Promise.resolve(null);
                        }

                        // Kareyi kucultmek telefonda cozumu hizlandiriyor; QR
                        // kadrajin buyuk kismini kapladigi icin okunurluk kalir.
                        const olcek = Math.min(1, 640 / Math.max(kaynak.videoWidth, kaynak.videoHeight));
                        tuval.width = Math.round(kaynak.videoWidth * olcek);
                        tuval.height = Math.round(kaynak.videoHeight * olcek);
                        cizim.drawImage(kaynak, 0, 0, tuval.width, tuval.height);

                        const kare = cizim.getImageData(0, 0, tuval.width, tuval.height);
                        const kod = jsQR(kare.data, kare.width, kare.height, { inversionAttempts: 'dontInvert' });

                        return Promise.resolve(kod ? kod.data : null);
                    };
                });
            }

            if (!navigator.mediaDevices || !window.isSecureContext) {
                bildir('Kamera yalnızca güvenli bağlantıda açılır. Aşağıdan kodu yazabilirsin.');
                return;
            }

            let durduruldu = false;

            Promise.all([
                cozucuHazirla().catch(function () { return null; }),
                navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }),
            ])
                .then(function (sonuc) {
                    const coz = sonuc[0];
                    const akis = sonuc[1];

                    if (coz === null) {
                        akis.getTracks().forEach(function (t) { t.stop(); });
                        bildir('QR okuyucu yüklenemedi. Aşağıdan kodu yazabilirsin.');
                        return;
                    }

                    video.srcObject = akis;

                    return video.play().then(function () {
                        (function tara() {
                            if (durduruldu) {
                                return;
                            }

                            coz(video)
                                .then(function (ham) {
                                    if (ham === null) {
                                        return setTimeout(tara, 150);
                                    }

                                    const adres = masaAdresi(ham, window.location.origin);

                                    if (adres === null) {
                                        bildir('Bu QR bir Kral Kafe masasına ait değil. Masadaki QR\'ı okut ya da kodu aşağıya yaz.');
                                        return setTimeout(tara, 1500);
                                    }

                                    durduruldu = true;
                                    akis.getTracks().forEach(function (t) { t.stop(); });
                                    window.location.href = adres;
                                })
                                .catch(function () {
                                    setTimeout(tara, 150);
                                });
                        })();
                    });
                })
                .catch(function () {
                    bildir('Kameraya erişilemedi. Aşağıdan kodu yazabilirsin.');
                });
        })();
    </script>
@endpush
