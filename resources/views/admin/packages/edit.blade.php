@extends('layouts.admin')

@section('title', 'Paket Düzenle - Kral Kafe')
@section('page-title', 'Paket: ' . $package->name)

@section('content')
    <div class="card" style="max-width: 640px;">
        <div class="card-body">
            <form action="{{ route('admin.packages.update', $package) }}" method="POST">
                @csrf
                @method('PUT')
                @include('admin.packages._form')
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                    <a href="{{ route('admin.packages.index') }}" class="btn btn-secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
@endsection
