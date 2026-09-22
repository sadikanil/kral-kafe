@extends('layouts.admin')

@section('title', 'Yeni Paket - Kral Kafe')
@section('page-title', 'Yeni Paket')

@section('content')
    <div class="card" style="max-width: 640px;">
        <div class="card-body">
            <form action="{{ route('admin.packages.store') }}" method="POST">
                @csrf
                @include('admin.packages._form')
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                    <a href="{{ route('admin.packages.index') }}" class="btn btn-secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
@endsection
