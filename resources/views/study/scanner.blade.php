@extends('layouts.user')

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
            border-radius: var(--radius);
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

    <div id="js-scanner-durum" class="alert alert-info" hidden></div>

    <div class="session-card">
        <strong>Masadaki kodu yaz</strong>
        <p class="text-muted">Kamera açılmazsa, masadaki etikette QR'ın altında yazan kodu buraya yazabilirsin.</p>

        <form method="POST" action="{{ route('table.find') }}" class="d-flex align-items-center gap-2">
            @csrf
            <input type="text" name="code" class="form-control" placeholder="MASA-XXXXXXXX"
                   value="{{ old('code') }}" maxlength="50" autocapitalize="characters" autocomplete="off" required>
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
        (function () {
            const video = document.getElementById('js-scanner-video');
            const durum = document.getElementById('js-scanner-durum');

            function bildir(mesaj) {
                durum.textContent = mesaj;
                durum.hidden = false;
            }

            if (!('BarcodeDetector' in window)) {
                bildir('Tarayıcın QR okumayı desteklemiyor. Aşağıdan kodu yazabilirsin.');
                return;
            }

            if (!navigator.mediaDevices || !window.isSecureContext) {
                bildir('Kamera yalnızca güvenli bağlantıda açılır. Aşağıdan kodu yazabilirsin.');
                return;
            }

            const detector = new BarcodeDetector({ formats: ['qr_code'] });
            let durduruldu = false;

            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(function (stream) {
                    video.srcObject = stream;
                    return video.play();
                })
                .then(function () {
                    (function tara() {
                        if (durduruldu) {
                            return;
                        }

                        detector.detect(video)
                            .then(function (kodlar) {
                                if (kodlar.length === 0) {
                                    return requestAnimationFrame(tara);
                                }

                                // QR'in tasidigi sey tam adres (StudyTable::getQrUrlAttribute).
                                // Dogrudan oraya gidiyoruz; kod ayiklamaya gerek yok.
                                durduruldu = true;
                                video.srcObject.getTracks().forEach(function (t) { t.stop(); });
                                window.location.href = kodlar[0].rawValue;
                            })
                            .catch(function () {
                                requestAnimationFrame(tara);
                            });
                    })();
                })
                .catch(function () {
                    bildir('Kameraya erişilemedi. Aşağıdan kodu yazabilirsin.');
                });
        })();
    </script>
@endpush
