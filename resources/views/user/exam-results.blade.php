@extends('layouts.user')

@section('title', 'Deneme Sonuçlarım - Kral Kafe')
@section('page-title', 'Deneme Sonuçlarım')

@section('content')
    @include('_deneme-sonuclari')

    @if($examResults->isEmpty())
        <div class="empty-state">
            <div class="empty-state-icon">📝</div>
            <div class="empty-state-title">Henüz sonuç girilmedi</div>
            <p class="text-muted">Deneme sonuçların açıklandığında kafe yönetimi buraya işler.</p>
        </div>
    @endif
@endsection
