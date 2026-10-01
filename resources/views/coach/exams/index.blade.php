@extends('layouts.app')

@section('title', 'Deneme Analizi - Kral Kafe')
@section('page-title', 'Deneme Analizi')

{{--
    Sonucu girilmis denemeler (1 Ekim 2026). Koc yalnizca atandigi
    ogrencilerin sonuclarini sayar; yonetici hepsini.
--}}
@section('content')
    <p class="text-muted mb-3">
        @if(auth()->user()->hasRole(\App\Enums\Role::Admin))
            Sonucu yayınlanan denemeler. Bir denemeye girip öğrencileri yan yana ve en çok eksik çıkan konuları görebilirsin.
        @else
            Koçluk yaptığın öğrencilerin deneme sonuçları. Bir denemeye girip öğrencilerini yan yana ve en çok eksik çıkan konuları görebilirsin.
        @endif
    </p>

    @if($exams->isEmpty())
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon"><x-icon name="target" /></div>
                    <div class="empty-state-title">Henüz deneme sonucu yok</div>
                    <p class="text-muted">Yönetici kurum PDF'ini yükleyip yayınlayınca burada görünür.</p>
                </div>
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-body p-0">
                @foreach($exams as $deneme)
                    <a href="{{ route('coach.exams.show', $deneme['event']) }}" class="list-row">
                        <span class="list-row-main">
                            <span class="list-row-title">{{ $deneme['event']->title }}</span>
                            <span class="list-row-sub">{{ $deneme['event']->exam_date->format('d.m.Y') }} · {{ $deneme['count'] }} öğrenci</span>
                        </span>
                        <span class="list-row-value">ort. <strong>{{ number_format($deneme['average'], 2, ',', '.') }}</strong> net</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endsection
