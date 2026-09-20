@extends('layouts.admin')

@section('title', 'Yeni Deneme - Kral Kafe')
@section('page-title', 'Yeni Deneme')

@section('content')
    <div class="card" style="max-width: 560px;">
        <div class="card-body">
            <form action="{{ route('admin.exams.store') }}" method="POST">
                @csrf
                @include('admin.exams._form', ['exam' => null])
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                    <a href="{{ route('admin.exams.index') }}" class="btn btn-secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
@endsection
