@extends('layouts.app')

@php
    $tz = config('kafe.timezone');
    $sayi = fn ($n, $basamak = 2) => number_format((float) $n, $basamak, ',', '.');
    $isaretli = fn ($n) => \App\Support\ExamResultDetail::signed((float) $n);
@endphp

@section('title', $result->event->title . ' - Kral Kafe')
@section('page-title', $result->event->title)

@section('page-actions')
    <a href="{{ $backUrl }}" class="btn btn-secondary btn-sm">← {{ $backLabel }}</a>
@endsection

{{--
    Deneme sonucu detayi (1 Ekim 2026). Ogrenci, veli, koc ve yonetici
    ayni sayfayi gorur (ExamResultViewController). Sayi var, sifat yok
    (README SS6.1-6): "eksik konu" kural (≥2 soru, basari <%50).
--}}
@section('content')
    <p class="text-muted mb-3">
        @if($showStudent)<strong>{{ $result->student->name }}</strong> · @endif
        {{ $result->event->exam_date->locale('tr')->translatedFormat('j F Y') }}
        @if($previous)
            · Önceki: <span>{{ $previous->event->title }}</span>
        @endif
    </p>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value">{{ $sayi($result->totalNet()) }}</div>
            <div class="stat-label">Toplam net
                @if($netChange !== null)
                    · <span class="{{ $netChange > 0 ? 'text-success' : ($netChange < 0 ? 'text-danger' : '') }}">{{ $isaretli($netChange) }}</span>
                @endif
            </div>
        </div>
        @if($result->score !== null)
            <div class="stat-card">
                <div class="stat-value">{{ $sayi($result->score, 3) }}</div>
                <div class="stat-label">Puan</div>
            </div>
        @endif
        @if($result->rank_institution)
            <div class="stat-card">
                <div class="stat-value">{{ number_format($result->rank_institution, 0, ',', '.') }}.</div>
                <div class="stat-label">Kurum sırası{{ $result->total_institution ? ' / ' . $result->total_institution : '' }}</div>
            </div>
        @endif
        <div class="stat-card">
            <div class="stat-value">{{ count($weak) }}</div>
            <div class="stat-label">Eksik konu</div>
        </div>
    </div>

    {{-- Yonetici notu ustte: sayilardan once okunmasi gereken baglam. --}}
    @if($result->note)
        <div class="alert alert-info mb-3">{{ $result->note }}</div>
    @endif

    @if($subjects !== [])
        <div class="card mb-3">
            <div class="card-header"><h4>Dersler</h4></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ders</th>
                                <th>D</th>
                                <th>Y</th>
                                <th>B</th>
                                <th>Net</th>
                                @if($previous)<th>Fark</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Bolum ara toplamlari karnedeki gibi (5 Ekim 2026). --}}
                            @foreach($tableRows as $ders)
                                <tr @class(['table-subtotal' => $ders['subtotal']])>
                                    <td>{{ $ders['name'] }}</td>
                                    <td>{{ $ders['correct'] }}</td>
                                    <td>{{ $ders['wrong'] }}</td>
                                    <td>{{ $ders['blank'] }}</td>
                                    <td><strong>{{ $sayi($ders['net']) }}</strong></td>
                                    @if($previous)
                                        <td class="{{ ($ders['change'] ?? 0) > 0 ? 'text-success' : (($ders['change'] ?? 0) < 0 ? 'text-danger' : 'text-muted') }}">
                                            {{ $ders['change'] === null ? '—' : $isaretli($ders['change']) }}
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if($ranks !== [])
        <div class="card mb-3">
            <div class="card-header"><h4>Sıralamalar</h4></div>
            <div class="card-body p-0">
                @foreach($ranks as $etiket => $metin)
                    <div class="list-row">
                        <span class="list-row-main"><span class="list-row-title">{{ $etiket }}</span></span>
                        <span class="list-row-value">{{ $metin }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($weak !== [])
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4>Eksik konular</h4>
                @if($planUrl)
                    <a href="{{ $planUrl }}" class="btn btn-sm btn-secondary">Plan sayfası →</a>
                @endif
            </div>
            <div class="card-body p-0">
                @foreach($weak as $konu)
                    {{-- Koc ve yonetici: tek dokunusla odev (5 Ekim 2026). Dugme
                         varken sayilar alt satirda; telefonda konu adi kirilmasin. --}}
                    @php($eylem = $topicActions[($konu['subject'] ?? '') . '|' . $konu['topic']] ?? null)
                    @php($sayilar = $konu['questions'] . ' soruda ' . $konu['correct'] . ' doğru')
                    <div class="list-row">
                        <span class="list-row-main">
                            <span class="list-row-title">{{ $konu['topic'] }}</span>
                            <span class="list-row-sub">{{ collect([$konu['subject'], $eylem ? $sayilar : null])->filter()->implode(' · ') }}</span>
                        </span>
                        @if(! $eylem)
                            <span class="list-row-value">{{ $sayilar }}</span>
                        @elseif($eylem['planned'])
                            <span class="badge badge-success">Planda</span>
                        @elseif($eylem['topic'])
                            <form method="POST" action="{{ route('coach.topics.plan', $eylem['topic']) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary">{{ auth()->user()->assignsOnlyHomework() ? 'Ödev ver' : 'Plana ekle' }}</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($topicGroups !== [])
        <h2 class="section-title">Konu konu sonuçlar</h2>
        @foreach($topicGroups as $ders => $konular)
            <details class="card mb-2 topic-group" @if($loop->first) open @endif>
                <summary class="card-header">
                    <h4>{{ $ders }}</h4>
                    <span class="text-muted">{{ collect($konular)->where('weak', true)->count() }} eksik · {{ count($konular) }} konu</span>
                </summary>
                <div class="card-body">
                    @foreach($konular as $konu)
                        <div class="topic-row">
                            <div class="progress-label">
                                <span>{{ $konu['topic'] }}@if($konu['weak']) <span class="badge badge-danger">Eksik</span>@endif</span>
                                <span>{{ $konu['correct'] }}/{{ $konu['questions'] }} · %{{ $konu['success'] }}</span>
                            </div>
                            <div class="progress" role="img" aria-label="{{ $konu['topic'] }}: yüzde {{ $konu['success'] }}">
                                <div class="progress-bar {{ $konu['weak'] ? 'progress-bar-weak' : '' }}" style="width: {{ $konu['success'] }}%;"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach
    @endif

@endsection
