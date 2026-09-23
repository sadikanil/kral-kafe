@extends('layouts.app')

@section('title', 'Ürün Düzenle - Kral Kafe')
@section('page-title', 'Ürün Düzenle')

@section('content')
    @include('admin.products._tabs')

    <div class="card" style="max-width: 600px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.products.update', $product) }}">
                @csrf
                @method('PUT')
                @include('admin.products._form')

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                    <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
