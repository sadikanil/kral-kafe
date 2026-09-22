@extends('layouts.admin')

@section('title', 'Ayarlar - Kral Kafe')
@section('page-title', 'Ayarlar')

@section('content')
    <div class="session-card">
        <strong>Kafe konumu</strong>
        <p class="text-muted">
            Çalışma oturumu başlatılırken öğrencinin konumu kaydediliyor. Kafenin
            koordinatı girilirse, onay kuyruğunda her oturumun kafeye uzaklığı
            görünür. Oturum <strong>engellenmez</strong> — bu yalnızca bir işaret.
        </p>

        @if($cafeLocation)
            <p class="text-muted">
                Kayıtlı konum: {{ number_format($cafeLocation['lat'], 6) }},
                {{ number_format($cafeLocation['lng'], 6) }}
            </p>
        @else
            <p class="text-muted">Henüz konum girilmedi; uzaklık hesaplanmıyor.</p>
        @endif

        <form method="POST" action="{{ route('admin.settings.location') }}">
            @csrf

            <div class="d-flex align-items-center gap-2 mb-2">
                <input type="text" name="latitude" id="js-konum-enlem" class="form-control"
                       placeholder="Enlem" value="{{ old('latitude', $cafeLocation['lat'] ?? '') }}" required>
                <input type="text" name="longitude" id="js-konum-boylam" class="form-control"
                       placeholder="Boylam" value="{{ old('longitude', $cafeLocation['lng'] ?? '') }}" required>
            </div>

            @error('latitude') <p class="text-danger">{{ $message }}</p> @enderror
            @error('longitude') <p class="text-danger">{{ $message }}</p> @enderror

            <div class="d-flex align-items-center gap-2">
                {{-- Kafedeyken basilacak: koordinati elle yazmak yerine olcerek al --}}
                <button type="button" class="btn btn-secondary" id="js-konum-al">Konumu buradan al</button>
                <button type="submit" class="btn btn-primary">Kaydet</button>
            </div>

            <p class="text-muted mt-2" id="js-konum-durum"></p>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        document.getElementById('js-konum-al').addEventListener('click', function () {
            const durum = document.getElementById('js-konum-durum');

            if (!navigator.geolocation) {
                durum.textContent = 'Tarayıcın konum desteklemiyor; koordinatı elle yazabilirsin.';
                return;
            }

            durum.textContent = 'Konum alınıyor…';

            navigator.geolocation.getCurrentPosition(
                function (konum) {
                    document.getElementById('js-konum-enlem').value = konum.coords.latitude.toFixed(7);
                    document.getElementById('js-konum-boylam').value = konum.coords.longitude.toFixed(7);
                    durum.textContent = 'Alındı (±' + Math.round(konum.coords.accuracy) + ' m). Kaydet\'e bas.';
                },
                function () {
                    durum.textContent = 'Konum alınamadı; koordinatı elle yazabilirsin.';
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        });
    </script>
@endpush
