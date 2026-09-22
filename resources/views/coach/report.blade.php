@extends('layouts.coach')

@section('title', $student->name . ' - Haftalık Rapor')
@section('page-title', $student->name . ' · Haftalık Rapor')

@section('topbar-actions')
    <a href="{{ route('coach.plan.show', $student) }}" class="btn btn-sm btn-secondary">Plan</a>
    <a href="{{ route('coach.notes.index', $student) }}" class="btn btn-sm btn-secondary">Notlar</a>
    <a href="{{ route('coach.plan.index') }}" class="btn btn-sm btn-secondary">← Öğrenciler</a>
@endsection

@section('content')
    @include('_haftalik-rapor')

    @if($report)
        <div class="card mb-3">
            <div class="card-header"><h4>Koç yorumu</h4></div>
            <div class="card-body">
                <p class="text-muted">
                    Bu yorum veliye ve öğrenciye görünür. Sayıları sistem üretir,
                    yorumu insan — rapordaki tek değerlendirme burası.
                </p>

                <form method="POST" action="{{ route('coach.report.comment', [$student, 'hafta' => $hafta]) }}">
                    @csrf
                    <div class="form-group">
                        <textarea name="coach_comment" class="form-control" rows="3" maxlength="2000"
                                  placeholder="Tempo iyi, deneme sayısını artıralım.">{{ $report->coach_comment }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </form>
            </div>
        </div>

        {{-- Rapor bilerek dondurulmus; gecikmis onay icin ACIK bir kacis yolu. --}}
        <form method="POST" action="{{ route('coach.report.regenerate', [$student, 'hafta' => $hafta]) }}">
            @csrf
            <button type="submit" class="btn btn-secondary">Sayıları yeniden hesapla</button>
        </form>
    @endif
@endsection
