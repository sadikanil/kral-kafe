@extends('layouts.app')

@section('title', 'Bildirimler - Kral Kafe')
@section('page-title', 'Bildirimler')

@section('content')
    @forelse($notifications as $bildirim)
        <div class="session-card mb-2">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <strong>{{ $bildirim->title }}</strong>
                @if($bildirim->read_at === null)<span class="badge badge-primary">Yeni</span>@endif
            </div>
            @if($bildirim->body)
                <div class="text-muted">{{ $bildirim->body }}</div>
            @endif
            <div class="text-muted" style="font-size: .8rem;">
                {{ $bildirim->created_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}
            </div>
        </div>
    @empty
        <p class="text-muted">Henüz bildirim yok.</p>
    @endforelse
@endsection
