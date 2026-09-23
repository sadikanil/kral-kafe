@extends('layouts.app')

@section('title', 'Canlı Ekran - Kral Kafe')
@section('page-title', 'Canlı Ekran')

@section('topbar-actions')
    <button onclick="window.location.reload()" class="btn btn-secondary btn-sm">↻ Yenile</button>
@endsection

@section('content')
    <div class="d-flex gap-2 mb-3" style="flex-wrap: wrap;">
        <div class="card" style="flex: 1; min-width: 150px;">
            <div class="card-body text-center">
                <div class="session-timer">{{ $sessions->count() }}</div>
                <div class="text-muted">İçeride</div>
            </div>
        </div>
        <div class="card" style="flex: 1; min-width: 150px;">
            <div class="card-body text-center">
                <div class="session-timer">{{ $freeTables }}</div>
                <div class="text-muted">Boş yer</div>
            </div>
        </div>
        <div class="card" style="flex: 1; min-width: 150px;">
            <div class="card-body text-center">
                <div class="session-timer">{{ $tableCount }}</div>
                <div class="text-muted">Toplam yer</div>
            </div>
        </div>
    </div>

    @if($anomalies->isNotEmpty())
        <div class="card mb-3">
            <div class="card-body">
                <h4 class="mb-2">Son 7 günün anomalileri</h4>
                <p class="text-muted mb-3" style="font-size: 0.8125rem;">
                    Bu oturumlar azami süreyi aştığı için otomatik kapatıldı. Çıkış
                    yapmayı unutmuş olabilirler; süreleri gerçek çalışmayı yansıtmayabilir.
                </p>

                @foreach($anomalies as $anomali)
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <div>
                            <strong>{{ $anomali->student->name }}</strong>
                            <div class="text-muted" style="font-size: 0.8125rem;">
                                {{ $anomali->table->name }} ·
                                {{ $anomali->started_at->timezone(config('kafe.timezone'))->format('H:i') }}
                                –
                                {{ $anomali->ended_at->timezone(config('kafe.timezone'))->format('H:i') }}
                            </div>
                        </div>
                        <span class="badge badge-danger">Süre aşımı</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @forelse($sessions as $session)
        @php $dakika = $session->minutesSoFar(); @endphp

        <div class="session-card mb-2">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        @if($mola = $session->openPause())
                            <span class="badge badge-warning">⏸ {{ $mola->kind->label() }}</span>
                        @else
                            <span class="live-dot"></span>
                        @endif
                        <strong>{{ $session->student->name }}</strong>
                    </div>
                    <div class="text-muted">
                        {{ $session->table->name }} ·
                        {{ $session->started_at->timezone(config('kafe.timezone'))->format('H:i') }}'den beri
                    </div>
                </div>

                <div class="text-right">
                    <div class="session-timer">
                        {{ sprintf('%02d:%02d', intdiv($dakika, 60), $dakika % 60) }}
                    </div>
                    @if($dakika >= config('kafe.azami_saat') * 60)
                        <span class="badge badge-danger">Süre aşımı</span>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="empty-state">
            <div class="empty-state-icon">🪑</div>
            <div class="empty-state-title">Şu anda içeride kimse yok</div>
            <p class="text-muted">Bir öğrenci masadaki QR'ı okutunca burada görünür.</p>
        </div>
    @endforelse

{{--
    Onay kuyrugu (Dalga 9).

    Burada, canli ekranin icinde: yonetici zaten gun boyu bu sayfayi acik
    tutuyor. Ustte kim iceride, altta neyi onaylamasi gerektigi.

    Karar icin gereken en az bilgi gosteriliyor - ogrenci, masa, saat araligi,
    sure. Yonetici karari zaten kafede gordukleriyle veriyor; ekranin isi
    hatirlatmak, ikna etmek degil.
--}}
@if($pending->isNotEmpty())
    <h2 class="mt-4">Onay bekleyen oturumlar ({{ $pending->count() }})</h2>
    <p class="text-muted">Onaylanana kadar bu süreler öğrencinin toplamına ve velinin paneline girmez.</p>

    <form method="POST" action="{{ route('admin.sessions.approve-many') }}" class="mb-2">
        @csrf
        @foreach($pending as $bekleyen)
            <input type="hidden" name="ids[]" value="{{ $bekleyen->id }}">
        @endforeach
        <button type="submit" class="btn btn-primary">Hepsini onayla ({{ $pending->count() }})</button>
    </form>

    @foreach($pending as $bekleyen)
        @php $dakika = $bekleyen->duration_minutes ?? $bekleyen->minutesSoFar(); @endphp

        <div class="session-card mb-2">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div>
                    <strong>{{ $bekleyen->student->name }}</strong>
                    <div class="text-muted">
                        {{ $bekleyen->table->name }} ·
                        {{ $bekleyen->started_at->timezone(config('kafe.timezone'))->format('H:i') }}–{{ $bekleyen->ended_at->timezone(config('kafe.timezone'))->format('H:i') }}
                        · {{ sprintf('%ds %ddk', intdiv($dakika, 60), $dakika % 60) }}
                    </div>

                    {{--
                        Konum bir ISARET, bir karar degil (Dalga 10b). Uc ayri
                        durum var ve ucu de farkli anlama geliyor:
                        konum yok (izin verilmemis), uzak (esigi asmis),
                        kafede. "Konum yok"u "uzak" saymak, izni kapali her
                        ogrenciyi supheli gosterirdi.
                    --}}
                    @php $mesafe = $bekleyen->distanceFromCafe(); @endphp

                    @if($bekleyen->latitude === null)
                        <span class="badge badge-warning">Konum yok</span>
                    @elseif($mesafe === null)
                        <span class="badge badge-info">Kafe konumu girilmedi</span>
                    @elseif($bekleyen->isFarFromCafe())
                        <span class="badge badge-danger">Kafeden uzak ({{ round($mesafe) }} m)</span>
                    @else
                        <span class="badge badge-success">Kafede ({{ round($mesafe) }} m)</span>
                    @endif
                </div>

                <div class="d-flex align-items-center gap-2">
                    <form method="POST" action="{{ route('admin.sessions.approve', $bekleyen) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">Onayla</button>
                    </form>

                    <form method="POST" action="{{ route('admin.sessions.reject', $bekleyen) }}" class="d-flex align-items-center gap-2">
                        @csrf
                        <input type="text" name="reason" class="form-control" placeholder="Red sebebi" maxlength="255" required>
                        <button type="submit" class="btn btn-danger">Reddet</button>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endif
@endsection
