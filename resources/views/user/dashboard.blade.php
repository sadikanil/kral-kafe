@extends('layouts.app')

@section('title', 'Panel - Kral Kafe')
@section('page-title', 'Hoş Geldin, ' . auth()->user()->name . '!')

{{--
    Ogrenci paneli. UX turu (23 Eyl): "calisma once".
      1. Acik oturum ya da "Calismaya basla" (QR)
      2. Bugun: takvimin bugunu (sabit program, ozel ders, deneme, plan)
      3. Sureler + haftalik hedef
      4. Paket, ders kirilimi, zayif konular, koc notlari, sayilmayanlar
    Para kartlari Adisyon'da, tuketim dokumu Odemeler'de.
--}}
@php
    use App\Support\Duration;
    $hedefDakika = $weeklyGoal?->target_minutes;
    $yuzde = $hedefDakika ? min(100, (int) round($weekMinutes / $hedefDakika * 100)) : null;
    $bugunMaddeleri = $today['items'];
@endphp

@section('content')
    @if($openSession)
        <div class="session-card mb-3">
            <div class="d-flex align-items-center gap-2 mb-2" style="justify-content: center;">
                <span class="live-dot"></span>
                <strong>Çalışıyorsun · {{ $openSession->table->name }}</strong>
            </div>

            @php $dakika = $openSession->minutesSoFar(); @endphp
            <div class="session-timer">{{ sprintf('%02d:%02d', intdiv($dakika, 60), $dakika % 60) }}</div>

            <p class="text-muted mb-3">
                {{ $openSession->started_at->timezone(config('kafe.timezone'))->format('H:i') }}'den beri · net
            </p>

            {{-- Dalga 23: duraklat, mola, ders etiketi sayacta. --}}
            <div class="session-actions">
                <a href="{{ route('session.timer') }}" class="btn btn-primary">⏱ Sayaca git</a>
                <form action="{{ route('session.end') }}" method="POST"
                      onsubmit="return confirm('Bugünkü çalışmayı bitirmek istiyor musun?')">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Bitir</button>
                </form>
            </div>
        </div>
    @elseif($canScan)
        {{-- Gunun ilk dokunusu: kamerayi uygulamanin icinde acar. --}}
        <a href="{{ route('table.scanner') }}" class="start-card mb-3">
            <span class="start-card-icon" aria-hidden="true">📷</span>
            <span>
                <span class="start-card-title">Çalışmaya başla</span>
                <span class="start-card-sub">Masandaki QR'ı okut</span>
            </span>
        </a>
    @endif

    @include('exams._geri-sayim')

    @include('exams._hatirlatici', ['calendarRoute' => 'user.exams'])

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>
                Bugün
                @if($bugunMaddeleri->isNotEmpty())
                    <span class="badge {{ $bugunMaddeleri->every(fn ($m) => $m->status === 'done') ? 'badge-success' : 'badge-info' }}">
                        {{ $bugunMaddeleri->where('status', 'done')->count() }} / {{ $bugunMaddeleri->count() }}
                    </span>
                @endif
            </h4>
            <a href="{{ route('user.plan') }}" class="btn btn-sm btn-secondary">Haftam →</a>
        </div>
        <div class="card-body">
            @include('_plan-gunu', ['gun' => $today, 'mode' => 'student', 'bos' => 'Bugün için plan yok.'])
        </div>
    </div>

    <div class="mini-stats mb-2">
        <div class="mini-stat">
            <div class="mini-stat-value">{{ Duration::human($todayMinutes) }}</div>
            <div class="mini-stat-label">Bugün</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value">{{ Duration::human($weekMinutes) }}</div>
            <div class="mini-stat-label">Bu hafta</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value">{{ Duration::human($monthMinutes) }}</div>
            <div class="mini-stat-label">Bu ay</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value">{{ $streak }} gün</div>
            <div class="mini-stat-label">Üst üste</div>
        </div>
    </div>
    {{-- Sureler yalnizca ONAYLI oturumlari sayar (Dalga 9); acik oturum
         sayacta. Bu satir olmadan ogrenci calisirken "Bugun 0 dk" gorup
         sebebini bilmiyordu. --}}
    <p class="text-muted mb-3" style="font-size: 0.8125rem;">Süreler görevli onayından sonra eklenir.</p>

    {{-- Hedef yoksa cubuk HIC cizilmez: bos bir cubuk "hedefin yok" demez,
         "hedefin var ama hic calismadin" der. --}}
    @if($hedefDakika)
        <div class="card mb-3">
            <div class="card-body">
                <div class="progress-label">
                    <span>Haftalık hedef</span>
                    <span>{{ Duration::human($weekMinutes) }} / {{ Duration::human($hedefDakika) }}</span>
                </div>
                <div class="progress">
                    <div class="progress-bar" style="width: {{ $yuzde }}%;"></div>
                </div>
                @if($yuzde >= 100)
                    <p class="text-success mb-0 mt-2">Bu haftanın hedefi tamam. 👏</p>
                @endif
            </div>
        </div>
    @endif

    @if($subscription)
        @php $subscription->syncPaymentStatus(); @endphp
        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center" style="flex-wrap: wrap; gap: 8px;">
                <div>
                    🎫 <strong>{{ $subscription->package->name }}</strong>
                    <span class="text-muted">· bitiş {{ $subscription->ends_on->locale('tr')->translatedFormat('j F') }}</span>
                </div>
                <span class="badge badge-{{ $subscription->payment_status->badgeClass() }}">
                    Ödeme: {{ $subscription->payment_status->label() }}
                </span>
            </div>
        </div>
    @endif

    {{--
        Bu haftanin ders kirilimi (Dalga 17a). "12 saat calisti" yerine
        "8 saat matematik, 0 saat Turkce". Kayit yoksa cizilmez.
    --}}
    @if($subjectBreakdown !== [])
        <div class="card mb-3">
            <div class="card-header"><h4>Ders kırılımı · bu hafta</h4></div>
            <div class="card-body">
                @foreach($subjectBreakdown as $ad => $dakika)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>{{ $ad }}</span>
                        <strong>{{ \App\Support\Duration::human($dakika) }}</strong>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @include('_zayif-konular')

    @include('_koc-notlari')

    {{--
        Onay bekleyen / reddedilen oturumlar (Dalga 9).

        Yukaridaki sure, seri ve hedef cubugu YALNIZCA onayli oturumlari
        sayiyor. Bu liste olmasa ogrenci iki saat calisip panelde sifir
        gorur ve sebebini hicbir yerde bulamazdi.
    --}}
    @if($notCredited->isNotEmpty())
        <h2 class="mt-4">Henüz sayılmayan oturumlar</h2>

        @foreach($notCredited as $oturum)
            @php $dakika = $oturum->duration_minutes ?? 0; @endphp

            <div class="session-card mb-2">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div>
                        <strong>{{ $oturum->table->name }}</strong>
                        <div class="text-muted">
                            {{ $oturum->started_at->timezone(config('kafe.timezone'))->format('d.m H:i') }}–{{ $oturum->ended_at->timezone(config('kafe.timezone'))->format('H:i') }}
                            · {{ \App\Support\Duration::human($dakika) }}
                        </div>
                        @if($oturum->rejection_reason)
                            <div class="text-muted">{{ $oturum->rejection_reason }}</div>
                        @endif
                    </div>

                    <span class="badge {{ $oturum->approval_status === \App\Enums\ApprovalStatus::Rejected ? 'badge-danger' : 'badge-warning' }}">
                        {{ $oturum->approval_status->label() }}
                    </span>
                </div>
            </div>
        @endforeach
    @endif
@endsection
