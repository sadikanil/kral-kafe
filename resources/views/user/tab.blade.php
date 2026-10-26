@extends('layouts.app')

@php($para = fn ($n) => \App\Support\TabSummary::money((float) $n))

@section('title', 'Adisyonum - Kral Kafe')
@section('page-title', 'Adisyonum')

{{--
    Adisyonum (DEV-506, 26 Ekim 2026). Masa QR'i once bunu acar
    (TableSessionController::show, $table dolu); menuden gelen ayni sayfayi
    masasiz gorur. Ust kart: ayin toplami ve odeme durumu; altinda buyuk
    "Urun ekle". Calisma oturumu masadan gelene ikinci adim. Gecmis bir ay
    (?ay=) salt okunur: ekleme ve geri alma yok.
--}}
@section('content')
    <section class="tab-hero mb-3" aria-labelledby="adisyon-ay">
        <div class="tab-hero-head">
            <span id="adisyon-ay" class="tab-hero-month">☕ {{ $monthLabel }}</span>
            @if($table)<span class="tab-hero-table">{{ $table->name }}</span>@endif
        </div>
        <div class="tab-hero-total">{{ $para($total) }}</div>
        <div class="tab-hero-meta">{{ $itemCount }} ürün · {{ $lines->count() }} kalem</div>
        <div class="tab-hero-row">
            <span>Ödeme durumu</span>
            @include('user._adisyon-odeme')
        </div>
    </section>

    @if($isCurrent)
        <a href="{{ route('user.tab.add', $table ? ['masa' => $table->qr_code] : []) }}" class="btn btn-primary btn-lg btn-block mb-3">☕ Ürün ekle</a>
    @else
        <a href="{{ route('user.tab') }}" class="btn btn-secondary btn-block mb-3">← Bu ayın adisyonu</a>
    @endif

    {{-- Masadan gelindiyse calisma ikinci adim (DEV-506). --}}
    @if($table)
        <div class="card mb-3">
            <div class="card-header"><h4>Çalışma</h4></div>
            <div class="card-body">
                @if($session && $session->study_table_id === $table->id)
                    <p class="mb-2"><span class="live-dot"></span> <strong>Çalışma sürüyor</strong> · {{ $table->name }}</p>
                    <a href="{{ route('session.timer') }}" class="btn btn-secondary btn-block">⏱ Sayaca git</a>
                @else
                    <p class="text-muted mb-2">
                        @if($session)
                            Şu anda {{ $session->table->name }} masasında açık bir çalışman var.
                        @else
                            Bu masada çalışmak istersen oturumu başlat.
                        @endif
                    </p>
                    <a href="{{ route('table.session', $table->qr_code) }}" class="btn btn-secondary btn-block">Çalışma oturumuna geç →</a>
                @endif
            </div>
        </div>
    @endif

    @if($latest->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header"><h4>Son eklenenler</h4></div>
            <div class="card-body">
                <ul class="log-list">
                    @foreach($latest as $kayit)
                        <li>
                            <span class="text-muted">{{ $kayit->consumed_at->timezone(config('kafe.timezone'))->format('d.m H:i') }}</span>
                            <span class="log-label">
                                {{ $kayit->product?->name }} ×{{ $kayit->quantity }}
                                <br>
                                @if($kayit->isCoveredByPackage())
                                    <span class="badge badge-success">Paketinde</span>
                                @else
                                    <strong>{{ $kayit->formatted_total }}</strong>
                                    @if($kayit->covered_quantity > 0)
                                        <span class="badge badge-info">{{ $kayit->covered_quantity }} paketten</span>
                                    @endif
                                @endif
                            </span>
                            @if($kayit->canUndo())
                                <form action="{{ route('user.tab.undo', $kayit) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary"
                                        aria-label="{{ $kayit->product?->name }} ×{{ $kayit->quantity }} kaydını geri al">Geri al</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header"><h4>Kalemler</h4></div>
        @if($lines->isEmpty())
            <div class="card-body">
                <p class="text-muted mb-0">{{ $isCurrent ? 'Bu ay henüz bir şey eklemedin.' : 'Bu ay adisyon yok.' }}</p>
            </div>
        @else
            <div class="card-body p-0">
                @foreach($lines as $kalem)
                    <div class="list-row">
                        <span class="list-row-main">
                            <span class="list-row-title">{{ $kalem['emoji'] ? $kalem['emoji'] . ' ' : '' }}{{ $kalem['name'] }}</span>
                            <span class="list-row-sub">×{{ $kalem['quantity'] }}@if($kalem['covered'] > 0) · {{ $kalem['covered'] }} paketten @endif</span>
                        </span>
                        <span class="list-row-value">{{ $para($kalem['total']) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if($history->isNotEmpty())
        <div class="card">
            <div class="card-header"><h4>Geçmiş adisyonlar</h4></div>
            <div class="card-body p-0">
                @foreach($history as $gecmis)
                    <a href="{{ route('user.tab', ['ay' => $gecmis['key']]) }}" class="list-row">
                        <span class="list-row-main">
                            <span class="list-row-title">{{ $gecmis['label'] }}</span>
                            <span class="list-row-sub">{{ $gecmis['itemCount'] }} ürün</span>
                        </span>
                        <span class="list-row-value">{{ $para($gecmis['total']) }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endsection
