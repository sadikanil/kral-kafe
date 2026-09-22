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
        <span class="text-muted">({{ $summary['subscription']->ends_on->format('d.m.Y') }}'e kadar)</span>
        · <span class="badge badge-{{ $summary['subscription']->payment_status->badgeClass() }}">Ödeme: {{ $summary['subscription']->payment_status->label() }}</span>
    </p>
@endif

@if($summary['openSession'])
    <div class="d-flex align-items-center gap-2 mb-3">
        <span class="live-dot"></span>
        <strong>Şu an içeride</strong>
        <span class="text-muted">
            — {{ $summary['openSession']->table->name }},
            {{ $summary['openSession']->started_at->timezone($tz)->format('H:i') }}'den beri
            ({{ Duration::human($summary['openSession']->minutesSoFar()) }})
        </span>
    </div>
@elseif($summary['lastSession'])
    <p class="text-muted mb-3">
        Son geliş: {{ $summary['lastSession']->started_at->timezone($tz)->format('d.m.Y H:i') }}
        – {{ $summary['lastSession']->ended_at->timezone($tz)->format('H:i') }}
        ({{ Duration::human($summary['lastSession']->minutesSoFar()) }})
    </p>
@else
    <p class="text-muted mb-3">Henüz kayıtlı bir çalışma yok.</p>
@endif

<div class="d-flex gap-2 mb-3" style="flex-wrap: wrap;">
    <div class="card" style="flex: 1; min-width: 120px;">
        <div class="card-body text-center">
            <div class="session-timer">{{ Duration::human($summary['todayMinutes']) }}</div>
            <div class="text-muted">Bugün</div>
        </div>
    </div>
    <div class="card" style="flex: 1; min-width: 120px;">
        <div class="card-body text-center">
            <div class="session-timer">{{ Duration::human($summary['weekMinutes']) }}</div>
            <div class="text-muted">Bu hafta</div>
        </div>
    </div>
    <div class="card" style="flex: 1; min-width: 120px;">
        <div class="card-body text-center">
            <div class="session-timer">{{ Duration::human($summary['monthMinutes']) }}</div>
            <div class="text-muted">Bu ay</div>
        </div>
    </div>
    <div class="card" style="flex: 1; min-width: 120px;">
        <div class="card-body text-center">
            <div class="session-timer">{{ $summary['streak'] }} gün</div>
            <div class="text-muted">Üst üste</div>
        </div>
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
