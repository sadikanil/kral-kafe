@extends('layouts.app')

@section('title', 'Deneme Düzenle - Kral Kafe')
@section('page-title', 'Deneme: ' . $exam->title)

@section('content')
    <div class="card" style="max-width: 560px;">
        <div class="card-body">
            <form action="{{ route('admin.exams.update', $exam) }}" method="POST">
                @csrf
                @method('PUT')
                @include('admin.exams._form')
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                    <a href="{{ route('admin.exams.index') }}" class="btn btn-secondary">Vazgeç</a>
                </div>
            </form>

            {{-- Kaldir burada: takvimdeki her deneme (resmi, serbest, gecmis) bu
                 sayfaya baglaniyor; "Yaklasan" listesi resmi ve serbest denemeleri
                 gostermedigi icin onlar baska hicbir yerden silinemiyordu.
                 Form ayri: PUT formunun icine gomulemez. --}}
            <form action="{{ route('admin.exams.destroy', $exam) }}" method="POST" class="mt-3"
                onsubmit="return confirm('Bu deneme takvimden kaldırılsın mı? Girilmiş sonuçları da silinir.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Kaldır</button>
            </form>
        </div>
    </div>
@endsection
