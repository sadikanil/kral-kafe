{{-- Bir ogrencinin calisma ozeti. Panel kartinda ve detay sayfasinda ayni
     parca kullanilir; iki yerde ayri yazilirsa sayilar bir gun ayrisir.
     Beklenen: $summary (ParentPanel\DashboardController::ozet). --}}
@php
    use App\Support\Duration;
    $tz = config('kafe.timezone');
    $hedefDakika = $summary['weeklyGoal']?->target_minutes;
    $yuzde = $hedefDakika ? min(100, (int) round($summary['weekMinutes'] / $hedefDakika * 100)) : null;
@endphp

@if($summary['subscription'] ?? null)
    <p class="mb-2">
        🎫 {{ $summary['subscription']->package->name }}
        <span class="text-muted">· bitiş {{ $summary['subscription']->ends_on->locale('tr')->translatedFormat('j F') }}</span>
        <span class="badge badge-{{ $summary['subscription']->payment_status->badgeClass() }}">Ödeme: {{ $summary['subscription']->payment_status->label() }}</span>
    </p>
@endif

@if($summary['openSession'])
    <p class="mb-3">
        <span class="live-dot"></span>
        <strong>Şu an içeride</strong>
        <span class="text-muted">
            · {{ $summary['openSession']->table->name }}
            · giriş {{ $summary['openSession']->started_at->timezone($tz)->format('H:i') }}
            <span style="white-space: nowrap;">({{ Duration::human($summary['openSession']->minutesSoFar()) }})</span>
        </span>
    </p>
@elseif($summary['lastSession'])
    <p class="text-muted mb-3">
        Son geliş: {{ $summary['lastSession']->started_at->timezone($tz)->format('d.m.Y H:i') }}
        – {{ $summary['lastSession']->ended_at->timezone($tz)->format('H:i') }}
        ({{ Duration::human($summary['lastSession']->minutesSoFar()) }})
    </p>
@else
    <p class="text-muted mb-3">Henüz kayıtlı bir çalışma yok.</p>
@endif

{{-- Ogrenci panelindeki kucuk kartlar: dev puntoda "3 sa 30 dk" uc satira kiriliyordu. --}}
<div class="mini-stats mb-3">
    <div class="mini-stat">
        <div class="mini-stat-value">{{ Duration::human($summary['todayMinutes']) }}</div>
        <div class="mini-stat-label">Bugün</div>
    </div>
    <div class="mini-stat">
        <div class="mini-stat-value">{{ Duration::human($summary['weekMinutes']) }}</div>
        <div class="mini-stat-label">Bu hafta</div>
    </div>
    <div class="mini-stat">
        <div class="mini-stat-value">{{ Duration::human($summary['monthMinutes']) }}</div>
        <div class="mini-stat-label">Bu ay</div>
    </div>
    <div class="mini-stat">
        <div class="mini-stat-value">{{ $summary['streak'] }} gün</div>
        <div class="mini-stat-label">Üst üste</div>
    </div>
</div>

{{-- Hedef yoksa cubuk hic cizilmez (ogrenci panelindeki kural). --}}
@if($hedefDakika)
    <div class="mb-2">
        <div class="progress-label">
            <span>Haftalık hedef</span>
            <span>{{ Duration::human($summary['weekMinutes']) }} / {{ Duration::human($hedefDakika) }}</span>
        </div>
        <div class="progress">
            <div class="progress-bar" style="width: {{ $yuzde }}%;"></div>
        </div>
        @if($yuzde >= 100)
            <p class="text-success mb-0 mt-2">Bu haftanın hedefi tamam. 👏</p>
        @endif
    </div>
@else
    <p class="text-muted mb-0">Haftalık hedef tanımlanmamış.</p>
@endif
