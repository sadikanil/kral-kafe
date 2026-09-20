@extends('layouts.admin')

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
        </div>
    </div>
@endsection
