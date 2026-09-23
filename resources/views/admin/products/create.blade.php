@extends('layouts.app')

@section('title', 'Yeni Ürün - Kral Kafe')
@section('page-title', 'Yeni Ürün Ekle')

@section('content')
    @include('admin.products._tabs')

    <div class="card" style="max-width: 600px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.products.store') }}">
                @csrf
                @include('admin.products._form')

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Ürün Ekle</button>
                    <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
