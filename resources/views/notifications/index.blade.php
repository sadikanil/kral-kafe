@extends('layouts.app')

@section('title', 'Bildirimler - Kral Kafe')
@section('page-title', 'Bildirimler')

@section('content')
    {{-- Telefon bildirimi (6 Ekim 2026): durum ve dugmeyi public/js/bildirim.js doldurur. --}}
    <section id="telefon" class="card mb-3" data-telefon-bildirim
        data-anahtar="{{ app(\App\Services\Push\WebPush::class)->publicKey() }}" data-adres="{{ route('push.store') }}">
        <div class="card-header"><h4>Telefon bildirimleri</h4></div>
        <div class="card-body">
            <p class="text-muted mb-2" data-durum role="status">Bu cihazda kapalı. Açarsanız bildirimler uygulama kapalıyken de gelir.</p>
            <button type="button" class="btn btn-primary" data-ac>Bu cihazda aç</button>
            <button type="button" class="btn btn-secondary" data-kapat hidden>Bu cihazda kapat</button>
        </div>
    </section>

    @forelse($notifications as $bildirim)
        <div class="session-card mb-2">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <strong>{{ $bildirim->title }}</strong>
                @if($bildirim->read_at === null)<span class="badge badge-primary">Yeni</span>@endif
            </div>
            @if($bildirim->body)
                <div class="text-muted">{{ $bildirim->body }}</div>
            @endif
            <div class="d-flex justify-content-between align-items-center gap-2">
                <span class="text-muted" style="font-size: .8rem;">
                    {{ $bildirim->created_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}
                </span>
                {{-- 5 Ekim 2026: deneme sonucu bildirimi detaya gider. --}}
                @if($hedef = $bildirim->url(auth()->user()))
                    <a href="{{ $hedef }}" class="btn btn-sm btn-secondary">Sonucu aç →</a>
                @endif
            </div>
        </div>
    @empty
        <p class="text-muted">Henüz bildirim yok.</p>
    @endforelse
@endsection
