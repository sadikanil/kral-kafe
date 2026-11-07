@extends('layouts.app')

@section('title', 'Liderlik - Kral Kafe')
@section('page-title', 'Liderlik')

@section('content')
    @php
        // Diger uyeler kisaltilmis adla ("Ayse K."); kendin ve yonetici tam ad (README SS6.1-4).
        $ad = fn ($u) => $yonetici || $u->id === auth()->id() ? $u->name : \App\Services\Leaderboard::kisaAd($u->name);
        $basHarf = function ($u) {
            $parca = preg_split('/\s+/u', trim($u->name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
            return mb_strtoupper(mb_substr($parca[0], 0, 1) . (count($parca) > 1 ? mb_substr(end($parca), 0, 1) : ''));
        };
        $benMi = fn ($s) => $s['user']->id === auth()->id();
        $adres = fn ($tarih, $donem = null) => route('user.leaderboard', ['donem' => $donem ?? $pencere['donem'], 'tarih' => $tarih]);
        $donemAdi = $pencere['donem'] === 'ay' ? 'ay' : 'hafta';
    @endphp

    <nav class="page-tabs" aria-label="Dönem">
        <a href="{{ route('user.leaderboard', ['donem' => 'hafta']) }}" class="page-tab {{ $pencere['donem'] === 'hafta' ? 'active' : '' }}"
           @if($pencere['donem'] === 'hafta') aria-current="page" @endif>Haftalık</a>
        <a href="{{ route('user.leaderboard', ['donem' => 'ay']) }}" class="page-tab {{ $pencere['donem'] === 'ay' ? 'active' : '' }}"
           @if($pencere['donem'] === 'ay') aria-current="page" @endif>Aylık</a>
    </nav>

    <div class="card mb-3">
        <div class="card-body">
            <div class="week-nav mb-3">
                <a href="{{ $adres($pencere['onceki']) }}" class="btn btn-sm btn-secondary" aria-label="Önceki {{ $donemAdi }}">←</a>
                <div class="week-nav-title">
                    <strong>{{ $pencere['etiket'] }}</strong>
                </div>
                @if($pencere['sonraki'])
                    <a href="{{ $adres($pencere['sonraki']) }}" class="btn btn-sm btn-secondary" aria-label="Sonraki {{ $donemAdi }}">→</a>
                @else
                    <span class="btn btn-sm btn-secondary leader-nav-bos" aria-hidden="true">→</span>
                @endif
            </div>

            @if($podyum->isEmpty())
                <div class="empty-state">
                    <div class="empty-state-title">Bu {{ $donemAdi }} henüz puan yok</div>
                    <p class="text-muted mb-0">Çalışma, deneme ve adisyon puan getirir; ilk puanı alan podyuma çıkar.</p>
                </div>
            @else
                {{-- DOM sirasi 1-2-3 (ekran okuyucu); gorunum 2-1-3 (CSS order). --}}
                <ol class="podium" aria-label="İlk üç">
                    @foreach($podyum as $s)
                        <li class="podium-place podium-{{ $s['sira'] }} {{ $benMi($s) ? 'is-me' : '' }}">
                            <span class="podium-avatar" aria-hidden="true">{{ $basHarf($s['user']) }}</span>
                            <span class="podium-name">{{ $ad($s['user']) }}</span>
                            @if($benMi($s))<span class="badge badge-info">Sen</span>@endif
                            <span class="podium-points">{{ $s['toplam'] }} puan</span>
                            @if($yonetici)
                                <span class="leader-detail">{{ $s['calisma'] }} çalışma · {{ $s['deneme'] }} deneme · {{ $s['alisveris'] }} adisyon</span>
                            @endif
                            <span class="podium-step"><span class="podium-rank">{{ $s['sira'] }}<span class="sr-only">. sıra</span></span></span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    @if($kalanlar->isNotEmpty())
        <div class="card mb-3">
            <div class="card-body p-0">
                <ol class="leader-list" start="4" aria-label="Diğer sıralar">
                    @foreach($kalanlar as $s)
                        <li class="leader-row {{ $benMi($s) ? 'is-me' : '' }}">
                            <span class="leader-rank">{{ $s['sira'] }}</span>
                            <span class="leader-name">
                                {{ $ad($s['user']) }}
                                @if($benMi($s))<span class="badge badge-info">Sen</span>@endif
                                @if($yonetici)
                                    <span class="leader-detail">{{ $s['calisma'] }} çalışma · {{ $s['deneme'] }} deneme · {{ $s['alisveris'] }} adisyon</span>
                                @endif
                            </span>
                            <span class="leader-points">{{ $s['toplam'] }} <small>puan</small></span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    @endif

    @unless($yonetici)
        <div class="card mb-3">
            <div class="card-body">
                <strong>Senin puanın</strong>
                @if($benim)
                    <p class="mb-2">{{ $benim['sira'] }}. sıradasın · <strong>{{ $benim['toplam'] }} puan</strong></p>
                    <div class="tab-hero-row"><span>Çalışma ({{ \App\Support\Duration::human($benim['dakika']) }})</span><span>{{ $benim['calisma'] }}</span></div>
                    <div class="tab-hero-row"><span>Deneme ({{ $benim['deneme_sayisi'] }})</span><span>{{ $benim['deneme'] }}</span></div>
                    <div class="tab-hero-row"><span>Adisyon</span><span>{{ $benim['alisveris'] }}</span></div>
                @else
                    <p class="text-muted mb-0">Bu {{ $donemAdi }} henüz puanın yok. Onaylanan çalışma, girdiğin deneme ve adisyonun puan getirir.</p>
                @endif
            </div>
        </div>
    @endunless

    <details class="card leader-help">
        <summary class="card-body">Puanlar nasıl hesaplanır?</summary>
        <div class="card-body leader-help-body">
            <ul class="mb-2">
                <li><strong>Çalışma:</strong> onaylanan her {{ $puan['calisma_dakika'] }} dakika 1 puan (molalar sayılmaz).</li>
                <li><strong>Deneme:</strong> girdiğin her deneme {{ $puan['deneme_katilim'] }} puan, netine göre {{ $puan['deneme_basari_azami'] }} puana kadar, aynı türdeki önceki denemeni geçersen +{{ $puan['deneme_gelisim'] }}.</li>
                <li><strong>Adisyon:</strong> her {{ $puan['alisveris_tl'] }} ₺ 1 puan, günde en fazla {{ $puan['alisveris_gunluk_azami'] }} (pakete dahil ürünler sayılmaz).</li>
            </ul>
            <p class="text-muted mb-0" style="font-size: 0.8125rem;">
                Hafta pazartesi başlar. Diğer üyeler yalnızca kısaltılmış adını ve toplam puanını görür;
                netlerin ve puan dökümün yalnızca sende.
            </p>
        </div>
    </details>
@endsection
