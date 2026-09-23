@extends('layouts.app')

@section('title', 'Çocuklarım - Kral Kafe')
@section('page-title', 'Çocuklarım')

@section('content')



    @include('exams._geri-sayim')

    @include('exams._hatirlatici', ['calendarRoute' => 'parent.exams'])

    @if($students->isEmpty())
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">👨‍👩‍👧</div>
                    <div class="empty-state-title">Hesabınıza bağlı öğrenci yok</div>
                    <p class="text-muted">
                        Çocuğunuzun çalışma bilgilerini görebilmek için kafe yönetimi
                        hesabınızı öğrenciyle eşleştirmeli. Lütfen yöneticiyle görüşün.
                    </p>
                </div>
            </div>
        </div>
    @else
        @foreach($students as $summary)
            <div class="card mb-3 animate-slide-up">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>{{ $summary['student']->name }}</h4>
                    <div class="d-flex gap-1">
                        <a href="{{ route('parent.report', $summary['student']) }}" class="btn btn-sm btn-primary">Rapor</a>
                        <a href="{{ route('parent.payments', $summary['student']) }}" class="btn btn-sm btn-secondary">Ödemeler</a>
                        <a href="{{ route('parent.student', $summary['student']) }}" class="btn btn-sm btn-secondary">Ayrıntı</a>
                    </div>
                </div>
                <div class="card-body">
                    @include('parent._ozet', ['summary' => $summary])
                </div>
            </div>
        @endforeach
    @endif
@endsection
