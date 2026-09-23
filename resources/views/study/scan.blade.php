@extends('layouts.app')

@section('title', $table->name . ' - Kral Kafe')
@section('page-title', $table->name)

@section('content')
    @php
        $buMasada = $session && $session->study_table_id === $table->id;
    @endphp

    @if($buMasada)
        <div class="session-card">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="live-dot"></span>
                <strong>Çalışma sürüyor — {{ $table->name }}</strong>
            </div>

            <p class="text-muted mb-3">
                Başlangıç: {{ $session->started_at->timezone(config('kafe.timezone'))->format('H:i') }}
                · Net {{ \App\Support\Duration::human($session->minutesSoFar()) }}
            </p>

            <a href="{{ route('session.timer') }}" class="btn btn-primary btn-block mb-2">⏱ Sayaca git</a>

            <form action="{{ route('session.end') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-danger">Çalışmayı Bitir</button>
            </form>
        </div>
    @elseif($session)
        <div class="alert alert-warning">
            Şu anda <strong>{{ $session->table->name }}</strong> masasında açık bir çalışmanız var.
            Aşağıdaki butona basarsanız o oturum kapanır ve {{ $table->name }} masasında yenisi başlar.
        </div>

        <div class="card">
            <div class="card-body text-center p-4">
                <h3 class="mb-3">{{ $table->name }}</h3>
                <form action="{{ route('table.session.start', $table->qr_code) }}" method="POST">
                    @csrf
                    {{-- Konum (Dalga 10b): izin verilmezse bos gider, oturum yine baslar --}}
                    <input type="hidden" name="latitude" class="js-konum-enlem">
                    <input type="hidden" name="longitude" class="js-konum-boylam">
                    <input type="hidden" name="accuracy" class="js-konum-dogruluk">
                    <button type="submit" class="btn btn-primary">Bu Masaya Geç</button>
                </form>
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-body text-center p-4">
                <h3 class="mb-1">{{ $table->name }}</h3>

                @if($occupant)
                    <p class="text-muted mb-3">
                        Bu masada <strong>{{ $occupant->student->name }}</strong> çalışıyor.
                    </p>
                @endif

                @if(! $table->is_active)
                    <p class="text-danger mb-3">Bu masa şu anda kullanımda değil.</p>
                @elseif(auth()->user()->hasRole(\App\Enums\Role::Student) && ! auth()->user()->entitlements()->table)
                    <p class="text-danger mb-0">Paketin masa kullanımını kapsamıyor. Yöneticiye danış.</p>
                @elseif(auth()->user()->hasRole(\App\Enums\Role::Student))
                    <p class="text-muted mb-3">Hoş geldin {{ auth()->user()->name }}!</p>
                    <form action="{{ route('table.session.start', $table->qr_code) }}" method="POST">
                        @csrf
                        {{-- Konum (Dalga 10b): izin verilmezse bos gider, oturum yine baslar --}}
                        <input type="hidden" name="latitude" class="js-konum-enlem">
                        <input type="hidden" name="longitude" class="js-konum-boylam">
                        <input type="hidden" name="accuracy" class="js-konum-dogruluk">
                        <button type="submit" class="btn btn-primary">Çalışmaya Başla</button>
                    </form>
                @else
                    <p class="text-muted mb-0">Çalışma oturumu yalnızca öğrenciler içindir.</p>
                @endif
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        // Konum ISTEGE BAGLI. Izin reddedilirse ya da zaman asimina ugrarsa
        // alanlar bos kalir ve oturum yine baslar - sunucu tarafi da oyle
        // dogruluyor. Ogrenciyi konum ekraninda bekletmemek icin sayfa
        // acilir acilmaz isteniyor, butona basinca degil.
        (function () {
            if (!navigator.geolocation) {
                return;
            }

            function doldur(secici, deger) {
                document.querySelectorAll(secici).forEach(function (alan) {
                    alan.value = deger;
                });
            }

            navigator.geolocation.getCurrentPosition(
                function (konum) {
                    doldur('.js-konum-enlem', konum.coords.latitude.toFixed(7));
                    doldur('.js-konum-boylam', konum.coords.longitude.toFixed(7));
                    doldur('.js-konum-dogruluk', Math.round(konum.coords.accuracy));
                },
                function () { /* izin yok: alanlar bos kalir, akis degismez */ },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
            );
        })();
    </script>
@endpush
