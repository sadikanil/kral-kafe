@extends('layouts.app')

@section('title', 'Canlı Ekran - Kral Kafe')
@section('page-title', 'Canlı Ekran')

@section('topbar-actions')
    <button onclick="window.location.reload()" class="btn btn-secondary btn-sm">↻ Yenile</button>
@endsection

@section('content')
    <div class="mini-stats mini-stats-3 mb-3">
        <div class="mini-stat">
            <div class="mini-stat-value">{{ $sessions->count() }}</div>
            <div class="mini-stat-label">İçeride</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value">{{ $freeTables }}</div>
            <div class="mini-stat-label">Boş yer</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value">{{ $tableCount }}</div>
            <div class="mini-stat-label">Toplam yer</div>
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

    {{-- UX turu (23 Eyl): tek satirlik liste. Her oturum ~120 px'lik bir
         karttiysa 32 yer doldugunda ekran bitmiyordu. --}}
    @if($sessions->isNotEmpty())
        <div class="card mb-3">
            <div class="card-body p-0">
                @foreach($sessions as $session)
                    @php $dakika = $session->minutesSoFar(); @endphp

                    <div class="live-row">
                        @if($mola = $session->openPause())
                            <span class="badge badge-warning">⏸ {{ $mola->kind->label() }}</span>
                        @else
                            <span class="live-dot"></span>
                        @endif
                        <div class="live-row-main">
                            <strong>{{ $session->student->name }}</strong>
                            <span class="text-muted">
                                {{ $session->table->name }} ·
                                {{ $session->started_at->timezone(config('kafe.timezone'))->format('H:i') }}'den beri
                            </span>
                        </div>
                        @if($dakika >= config('kafe.azami_saat') * 60)
                            <span class="badge badge-danger">Süre aşımı</span>
                        @endif
                        <span class="live-row-time">{{ sprintf('%02d:%02d', intdiv($dakika, 60), $dakika % 60) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="empty-state">
            <div class="empty-state-icon">🪑</div>
            <div class="empty-state-title">Şu anda içeride kimse yok</div>
            <p class="text-muted">Bir öğrenci masadaki QR'ı okutunca burada görünür.</p>
        </div>
    @endif

{{--
    Onay kuyrugu (Dalga 9).

    Burada, canli ekranin icinde: yonetici zaten gun boyu bu sayfayi acik
    tutuyor. Ustte kim iceride, altta neyi onaylamasi gerektigi.

    Karar icin gereken en az bilgi gosteriliyor - ogrenci, masa, saat araligi,
    sure. Yonetici karari zaten kafede gordukleriyle veriyor; ekranin isi
    hatirlatmak, ikna etmek degil.
--}}
@if($pending->isNotEmpty())
    <h2 class="mt-4" id="onay">Onay bekleyen oturumlar ({{ $pending->count() }})</h2>
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

        {{-- Telefonda bilgi ustte, kararlar altta; eskiden uc sutun sikisip
             saat araligi bes satira bolunuyordu. --}}
        <div class="approval-card mb-2">
                <div class="approval-info">
                    <strong>{{ $bekleyen->student->name }}</strong>
                    <div class="text-muted">
                        {{ $bekleyen->table->name }} ·
                        {{ $bekleyen->started_at->timezone(config('kafe.timezone'))->format('H:i') }}–{{ $bekleyen->ended_at->timezone(config('kafe.timezone'))->format('H:i') }}
                        · {{ \App\Support\Duration::human($dakika) }}
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

                <div class="approval-actions">
                    <form method="POST" action="{{ route('admin.sessions.approve', $bekleyen) }}" class="approve-form">
                        @csrf
                        <button type="submit" class="btn btn-primary">Onayla</button>
                    </form>

                    <form method="POST" action="{{ route('admin.sessions.reject', $bekleyen) }}" class="reject-form">
                        @csrf
                        <input type="text" name="reason" class="form-control" placeholder="Red sebebi" maxlength="255" required>
                        <button type="submit" class="btn btn-danger">Reddet</button>
                    </form>
                </div>
        </div>
    @endforeach
@endif
@endsection
